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

1. verifica PHP, PDO SQLite e backend crittografico;
2. genera `APP_SECRET` locale se assente;
3. installa Composer senza auto-script impliciti;
4. valida il kernel con `cache:clear`;
5. applica le migrazioni;
6. esegue `app:system:check`;
7. ricrea completamente `var/test.db`, inclusi eventuali `-wal` e `-shm`;
8. applica le migrazioni test;
9. esegue test di dominio e PHPUnit.

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

Non usare `0.0.0.0/0` o `::/0` come scorciatoia: renderebbe l'area admin pubblicamente raggiungibile senza autenticazione.

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

M1.7 non include ancora autenticazione admin: una demo Internet con amministrazione remota richiede la milestone successiva.

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
