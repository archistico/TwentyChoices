# Runbook operativo

## Bootstrap

Windows:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\bootstrap.ps1
```

Linux/macOS:

```bash
./scripts/bootstrap.sh
```

Il bootstrap:

1. esegue il preflight condiviso per PHP, estensioni, PDO SQLite, crypto e filesystem;
2. genera `APP_SECRET` locale se assente;
3. installa Composer senza auto-script impliciti;
4. valida il kernel con `cache:clear`;
5. applica le migrazioni;
6. esegue `app:installation:verify` su database, migrazioni, seed e PRAGMA;
7. esegue `app:system:check`;
8. esegue i test di dominio;
9. avvia `bin/phpunit`, che ricrea autonomamente `var/test.db` (inclusi eventuali `-wal`, `-shm` e `-journal`) e applica tutte le migrazioni test prima della suite.


## Gate M1.9.1 — installazione pulita e ripetibile

La verifica deve partire da una nuova estrazione dello ZIP, prima di creare `.env.local`, `vendor/` o database runtime.

Windows:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.1.ps1
```

Linux/macOS:

```bash
./scripts/verify-m1.9.1.sh
```

Gli script eseguono package audit, bootstrap completo due volte e verifica finale dell'installazione. Il secondo bootstrap è parte del gate: deve terminare senza duplicazioni, errori di migrazione o contaminazione del database test.

Comandi diagnostici singoli:

```bash
php tools/package-audit.php
php tools/bootstrap-preflight.php
php bin/console app:installation:verify
```

Dettagli e checklist: `docs/16-m1.9.1-environment-database-verification.md`.

## Gate combinato M1.9.2 + M1.9.2.1 + hotfix M1.9.2.1.3

Usare il gate correttivo più recente; resta rieseguibile anche su una working copy già inizializzata e dopo precedenti esecuzioni PHPUnit:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.2.1.3.ps1
```

oppure:

```bash
./scripts/verify-m1.9.2.1.3.sh
```

Lo script verifica prima la coerenza della baseline PHP 8.4/Composer e la policy del timer monotono, poi esegue bootstrap completo, incluse tutte le migrazioni presenti nella release corrente, suite regressiva e gate transazionale `app:verification:catalog-round --env=test`. La hotfix rende atomico il distacco di `choice_pair_id` dagli snapshot quando una coppia regolare viene eliminata. Lo scenario catalogo/round viene sempre rollbackato e non lascia dati di prova persistiti.

Dettagli e checklist: `docs/17-m1.9.2-catalog-round-verification.md` e `docs/18-m1.9.2.1-runtime-timing-hardening.md`.


## Gate M1.9.3 — Cryptographic Commitment Verification

Dopo la baseline validata M1.9.2.1.3 usare:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.3.ps1
```

oppure:

```bash
./scripts/verify-m1.9.3.sh
```

Lo script riesegue integralmente la baseline precedente e poi `app:verification:cryptographic-commitment --env=test`. Il gate apre un round soltanto dentro una transazione di verifica, ricostruisce il commitment, prova le quattro manomissioni obbligatorie, controlla autenticazione/context binding dei ciphertext, immutabilità SQLite e non-disclosure dei segreti durante `ACTIVE`; infine esegue rollback e ricontrolla il release manifest.

Dettagli: `docs/22-m1.9.3-cryptographic-commitment-verification.md`.

## Gate M1.9.4 — Play Start & Accounting Verification

Dopo la baseline validata M1.9.3 usare:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.4.1.ps1
```

oppure:

```bash
./scripts/verify-m1.9.4.1.sh
```

Lo script riesegue tutta la baseline M1.9.3 e poi `app:verification:play-start-accounting --env=test`. Il gate verifica token anonimi hashati, codici play opachi, idempotenza dello start, contabilizzazione 100/80/20, riconciliazione jackpot, protezioni anti-duplicato e rollback atomico sotto fault injection. I test HTTP verificano anche la pre-emissione del cookie sulla Home e il comportamento fail-closed se il POST non possiede una sessione anonima già valida.

Dettagli: `docs/23-m1.9.4-play-start-accounting-verification.md`.

### Esecuzione diretta PHPUnit

Anche l'esecuzione diretta è deterministica:

```powershell
php bin/phpunit
```

Il wrapper ricrea sempre il database SQLite di test prima di avviare PHPUnit. Questo evita che un test browser interrotto o fallito lasci round, giocate, rate limit o account che contaminino la successiva esecuzione. Per esigenze diagnostiche eccezionali il reset può essere saltato impostando `TWENTY_CHOICES_SKIP_TEST_DB_RESET=1`, ma non è la modalità normale di validazione.

## Avvio locale

```powershell
php -S 127.0.0.1:8000 -t public
```

Controlli:

```text
http://127.0.0.1:8000/health
http://127.0.0.1:8000/ready
http://127.0.0.1:8000/admin/diagnostica
```

## Diagnostica CLI

```powershell
php bin/console app:system:check
```

Un errore critico produce exit code non zero. Un warning operativo mantiene exit code zero ma deve essere valutato.

## Configurazione amministrativa

Default:

```dotenv
ADMIN_ALLOWED_IPS=127.0.0.1,::1
```

Per consentire una rete privata esplicita:

```dotenv
ADMIN_ALLOWED_IPS=127.0.0.1,::1,192.168.10.0/24
```

Non usare `0.0.0.0/0` o `::/0` come scorciatoia: eliminerebbe la barriera di rete aggiuntiva dell'allowlist e lascerebbe l'area admin esposta alla sola autenticazione applicativa.

Dietro reverse proxy configurare correttamente i trusted proxy Symfony prima di affidarsi a `getClientIp()`. Non fidarsi automaticamente di header `X-Forwarded-For` provenienti da client arbitrari.

## Produzione tecnica/demo remota

Prima di esporre l'applicazione:

- usare `APP_ENV=prod`;
- generare un `APP_SECRET` lungo e casuale;
- usare HTTPS;
- impostare `DEFAULT_URI` sull'origin HTTPS reale;
- restringere `ADMIN_ALLOWED_IPS`;
- aggiungere rate limiting anche al reverse proxy;
- proteggere filesystem e `.env.local`;
- configurare backup e rotazione log;
- non pubblicare `var/data.db`, `var/log` o `.env.local` nella document root.

M1.8 richiede sia autenticazione individuale sia allowlist IP. Per una demo remota seguire anche `docs/14-release-checklist.md`.

## Security log

File:

```text
var/log/security.jsonl
```

È append-only a livello applicativo, ma non protetto da un amministratore del filesystem.

Controllare almeno:

- picchi `RATE_LIMITED`;
- `ADMIN_ACCESS_DENIED`;
- aumento anomalo di `HTTP_EXCEPTION`.

La rotazione non è gestita dall'app. Usare strumenti del sistema operativo o del runtime di hosting.

## Backup SQLite

Con WAL attivo non copiare soltanto `var/data.db` mentre l'applicazione sta scrivendo.

Strategie sicure:

1. fermare le scritture e creare una copia coerente;
2. usare un meccanismo di backup SQLite che includa lo stato WAL;
3. verificare il backup con `PRAGMA integrity_check` prima di considerarlo valido.

Prima di un ripristino conservare sempre una copia del database danneggiato per analisi.

## Contesa e `database is locked`

Runtime M1.7:

```text
busy_timeout = 5000 ms
journal_mode = WAL
synchronous = FULL
```

Se gli errori di lock diventano frequenti:

1. verificare che non esistano transazioni inutilmente lunghe;
2. controllare volume di simulazioni e scritture concorrenti;
3. non aumentare il timeout indefinitamente;
4. pianificare la migrazione a PostgreSQL se il carico supera il modello single-writer di SQLite.

## Spazio disco

`app:system:check` segnala warning sotto 100 MiB liberi nella directory del database.

Monitorare anche:

```text
var/data.db
var/data.db-wal
var/data.db-shm
var/log/security.jsonl
```

## Incidente su audit

Se la diagnostica indica catena audit non valida:

1. interrompere le operazioni amministrative che modificano lo stato;
2. non tentare di “riparare” manualmente gli hash;
3. copiare database e log per analisi;
4. identificare la prima `sequence_number` non valida;
5. confrontare backup e ricevute pubbliche;
6. ripristinare soltanto da una fonte coerente e verificata.

## Incidente durante settlement

Il settlement è atomico. Dopo un errore:

```powershell
php bin/console app:system:check
```

Verificare:

- stato del round precedente;
- presenza di un solo `JACKPOT_PAYOUT`;
- unicità di `winner_play_id`;
- esistenza di un solo round `ACTIVE`;
- integrità audit.

I test M1.7 coprono esplicitamente rollback completo quando fallisce la creazione del round successivo.

## Gestione account amministrativi

Primo account:

```bash
php bin/console app:admin:create admin --role=SUPER_ADMIN
```

Creare account distinti per persona e assegnare il ruolo minimo necessario. Il database impedisce la rimozione dell'ultimo `SUPER_ADMIN` attivo. Un cambio password, ruolo o stato invalida la sessione precedente tramite `auth_version`.
