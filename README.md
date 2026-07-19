# TwentyChoices

Prototipo gratuito e simulatore tecnico di un gioco a venti scelte binarie. Ogni round usa una sola strada segreta globale; il primo percorso corretto validato dal server chiude il round e avvia il reset.

> Tutti gli importi sono virtuali. Il progetto non integra pagamenti, depositi, prelievi o premi con valore reale.

## Stato del progetto

Milestone corrente: **M1.9.1 — Environment & Database Verification (implementata, in attesa di validazione del gate su estrazione pulita)**.

M1.9.1 avvia la fase di Verification & Hardening sulla baseline M1.8.2. Introduce un preflight PHP unico per Windows/Unix, audit automatico del pacchetto pulito, verifica post-migrazione di database/migrazioni/seed/PRAGMA, reset del DB test testabile e script che eseguono due bootstrap consecutivi per dimostrare la ripetibilità dell'installazione. Nessuna regola di gioco viene modificata.

Prima di avviare M2.1 è stata pianificata la fase **M1.9 — Verification & Hardening**, composta da 15 milestone bloccanti che verificano l’intero processo pezzo per pezzo. Il piano completo è in `docs/15-verification-hardening-plan.md`.

Il sistema può ora:

- gestire account amministrativi con password hashata e ruoli `SUPER_ADMIN`, `OPERATOR`, `AUDITOR`;
- richiedere sia rete autorizzata sia sessione autenticata per ogni route `/admin`;
- invalidare una sessione esistente dopo cambio password, ruolo o disattivazione;
- impedire a livello SQLite la rimozione dell'ultimo `SUPER_ADMIN` attivo;
- aprire round verificabili con strada segreta globale e commitment pubblico;
- eseguire giocate da venti scelte con timer server-side e token monouso;
- assegnare atomicamente la vittoria al primo percorso corretto validato;
- congelare il montepremio, interrompere le altre giocate e creare crediti di ripartenza;
- aprire automaticamente il round successivo da 10.000,00 € virtuali;
- pubblicare percorso vincente e nonce dopo il settlement e verificare il commitment;
- emettere ricevute immutabili `V-...` e consultare lo storico pubblico;
- eseguire simulazioni statistiche isolate e riproducibili;
- applicare rate limiting, request ID, security logging e hardening SQLite;
- usare CSP `script-src 'self'; style-src 'self'` senza CSS/JS inline;
- mostrare pagine errore 403/404/429/500 coerenti e non informative;
- verificare integralmente la catena hash dell'audit;
- coprire con test E2E login/ruoli e percorso vincente 1/20 → reset globale/credito.

## Requisiti

- PHP 8.3 o 8.4
- Composer
- Estensioni PHP: `ctype`, `iconv`, `pdo`, `pdo_sqlite`
- almeno uno tra:
  - Sodium con `sodium_crypto_secretbox`;
  - OpenSSL con `AES-256-GCM`

Symfony 7.4 LTS richiede PHP 8.2 o superiore; il progetto impone PHP 8.3 come propria baseline.

## Installazione o aggiornamento Windows

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\bootstrap.ps1
php -S 127.0.0.1:8000 -t public
```

## Installazione o aggiornamento Linux/macOS

```bash
./scripts/bootstrap.sh
php -S 127.0.0.1:8000 -t public
```

Verifica completa M1.9.1 da una nuova estrazione del pacchetto:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.1.ps1
```

oppure:

```bash
./scripts/verify-m1.9.1.sh
```

La verifica esegue l'audit del pacchetto e due bootstrap consecutivi, quindi controlla nuovamente database, migrazioni, seed e PRAGMA.

Il bootstrap genera automaticamente un `APP_SECRET` casuale in `.env.local` se non è già presente. Il file è escluso da Git e non viene distribuito nello ZIP. L’area amministrativa è limitata per default a `127.0.0.1` e `::1` tramite `ADMIN_ALLOWED_IPS`.

Dopo la prima migrazione crea il primo amministratore:

```powershell
php bin/console app:admin:create admin --role=SUPER_ADMIN
```

Se `--password` non è specificata, viene richiesta in modo nascosto. Non esistono credenziali predefinite nello ZIP.

## Errore `could not find driver`

Doctrine usa esplicitamente il driver `pdo_sqlite`: `var/data.db` per lo sviluppo e `var/test.db` per i test. La connessione non dipende da `DATABASE_URL`.

Per controllare manualmente su Windows:

```powershell
where.exe php
php --ini
php -r "print_r(PDO::getAvailableDrivers());"
```

Nel file indicato come `Loaded Configuration File` devono essere abilitate almeno:

```ini
extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3
```

## Verifica rapida senza Composer

```bash
php tools/domain-tests.php
```

## Endpoint

- `/` — home del simulatore
- `/health` — liveness JSON minimale dell'applicazione
- `/ready` — readiness JSON con controllo accesso allo schema SQLite
- `/round/{codice}` — commitment e materiale di verifica del round
- `/storico` — storico pubblico dei round verificabili
- `/verifica/{codice}` — ricevuta verificabile della singola giocata
- `POST /gioca/inizia` — avvio o ripresa della giocata corrente
- `/gioca/{codice}` — step corrente, protetto dalla sessione anonima
- `POST /gioca/{codice}/scelta` — invio server-side della scelta
- `/admin/login` — autenticazione amministrativa
- `/admin` — dashboard amministrativa autorizzata per ruolo
- `/admin/utenti` — gestione account amministrativi (`SUPER_ADMIN`)
- `/admin/round` — apertura e storico dei round (`SUPER_ADMIN`, `OPERATOR`)
- `/admin/simulazioni` — dashboard e storico; esecuzione nuova simulazione consentita a `SUPER_ADMIN`/`OPERATOR`
- `/admin/simulazioni/{codice}` — dettaglio di una simulazione
- `/admin/simulazioni/{codice}/csv` — esportazione CSV aggregata
- `/admin/diagnostica` — controlli SQLite, spazio operativo e integrità audit (`SUPER_ADMIN`, `AUDITOR`)
- `/admin/scelte` — catalogo amministrativo delle coppie (`SUPER_ADMIN`, `OPERATOR`)
- `/admin/scelte/nuova` — creazione di una coppia regolare

## Apertura del round

L'operazione è atomica. Nella stessa transazione vengono creati:

1. record `game_round` in stato `PREPARING`;
2. 20 snapshot delle domande;
3. movimento `BANK_SEED` da 1.000.000 centesimi virtuali;
4. passaggio del round a `ACTIVE`.

Se uno dei passaggi fallisce, l'intera apertura viene annullata.

Il percorso e il nonce sono cifrati con autenticazione. Il ciphertext è legato all'identificativo interno e al tipo di segreto, quindi non può essere spostato su un altro round o scambiato tra percorso e nonce.

## Verifica del commitment

Il commitment usa il payload canonico:

```text
twenty-choices-v1:<round-code>:<question-set-hash>:<20-bits>:<nonce-hex>
```

Durante il round vengono pubblicati soltanto:

- codice del round;
- hash del set di domande;
- commitment SHA-256;
- data di apertura;
- montepremio virtuale.

Percorso e nonce restano cifrati durante il round. Nel passaggio atomico a `SETTLED` vengono pubblicati in colonne dedicate e rese immutabili; chiunque può quindi ricalcolare il commitment.


## Flusso della giocata

Una partecipazione standard registra virtualmente 1,00 € come 0,80 € al montepremio e 0,20 € all'organizzazione. Ogni scelta usa un token monouso e un timer server-side immutabile.

Alla ventesima scelta la stessa transazione:

1. registra la risposta finale;
2. ricostruisce il percorso completo;
3. decifra e verifica il percorso segreto contro il commitment;
4. marca la giocata come `COMPLETED_LOST`, oppure tenta atomicamente `ACTIVE → WON`;
5. se vince, congela il jackpot, registra il payout virtuale, interrompe le altre giocate e crea i crediti;
6. apre il round successivo da 10.000,00 €;
7. pubblica percorso vincente e nonce e chiude il vecchio round come `SETTLED`;
8. emette le ricevute verificabili delle giocate terminali nella stessa unità di lavoro;
9. esegue il commit di tutto il blocco.

Se qualunque passaggio fallisce, l'intera chiusura viene annullata.

Una giocata interrotta passa a `CREDITED`. Alla successiva partecipazione il credito viene consumato automaticamente come `RESTART_CREDIT`: il nuovo round non riceve un secondo contributo da 0,80 € e l'organizzazione non riceve un secondo 0,20 €.

## Test

`php bin/phpunit` ricrea autonomamente `var/test.db` e applica tutte le migrazioni prima della suite. Il bootstrap esegue quindi:

```bash
php tools/domain-tests.php
php bin/phpunit
```

La suite M1.9.1 contiene **84 metodi PHPUnit**, pari a **86 casi effettivi** considerando il data provider di `WinningPathTest`. Il runner indipendente contiene **20 verifiche**.

## Documentazione

- `docs/01-domain-specification.md`
- `docs/02-architecture.md`
- `docs/03-roadmap.md`
- `docs/04-validation.md`
- `docs/05-choice-catalog.md`
- `docs/06-round-opening.md`
- `docs/07-play-flow.md`
- `docs/08-round-settlement.md`
- `docs/09-public-verification.md`
- `docs/10-simulation-statistics.md`
- `docs/11-security-robustness.md`
- `docs/12-operations-runbook.md`
- `docs/13-admin-auth-e2e.md`
- `docs/14-release-checklist.md`
- `docs/15-verification-hardening-plan.md`
- `docs/16-m1.9.1-environment-database-verification.md`


## Simulazioni statistiche

Le simulazioni M1.6 sono completamente isolate da round e giocate reali. Non leggono la strada segreta, non creano movimenti contabili e non modificano il jackpot.

Dal browser si possono eseguire fino a 250.000 giocate per run. Per elaborazioni più grandi:

```bash
php bin/console app:simulation:run --plays=1000000 --profile=UNIFORM --seed=20260718
```

Profili disponibili: `UNIFORM`, `FIXED_A_BIAS`, `ALTERNATING_BIAS`. I profili con bias sono modelli sintetici, non dati empirici sul comportamento umano.

Dettagli: `docs/10-simulation-statistics.md`.

## Sicurezza e diagnostica

M1.8 mantiene il rate limiting SQLite-backed, request ID, security logging e allowlist IP di M1.7, aggiungendo autenticazione amministrativa individuale e autorizzazioni per ruolo. La CSP non consente più inline script **né inline style**: JavaScript e CSS sono serviti da `public/play.js` e `public/app.css`.

La sessione amministrativa usa il cookie `TWENTYCHOICESSESSID` con `HttpOnly`, `SameSite=Lax` e `Secure=auto`. Password, ruolo e stato account sono protetti da una `auth_version` monotona che invalida sessioni obsolete.

Controllo operativo:

```bash
php bin/console app:system:check
```

Configurazione admin predefinita:

```dotenv
ADMIN_ALLOWED_IPS=127.0.0.1,::1
```

Dettagli: `docs/11-security-robustness.md` e `docs/12-operations-runbook.md`.
