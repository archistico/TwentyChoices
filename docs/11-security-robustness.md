# M1.7 — Sicurezza, robustezza e osservabilità

## Scopo

M1.7 rafforza il simulatore contro abuso HTTP, esposizione accidentale dell'area amministrativa, leakage di segreti nei log, errori SQLite sotto contesa e failure parziali durante il settlement.

Il progetto resta un prototipo gratuito. Le misure di questa milestone riducono il rischio tecnico, ma non trasformano l'applicazione in una piattaforma pronta per denaro reale o Internet pubblico senza ulteriori controlli di identità, infrastruttura e deployment.

## Superfici protette

### Gameplay

Gli endpoint di avvio, caricamento step e invio scelta sono limitati con finestre temporali server-side. Il rate limiter usa chiavi HMAC-SHA-256: indirizzo IP e cookie di sessione non vengono memorizzati in chiaro nella tabella di throttling.

Profili correnti:

| Scope | Limite | Finestra |
|---|---:|---:|
| Avvio giocata per IP | 20 | 60 s |
| Caricamento step per sessione | 120 | 60 s |
| Caricamento step per IP | 240 | 60 s |
| Invio scelta per sessione | 40 | 60 s |
| Invio scelta per IP | 120 | 60 s |
| Verifica ricevuta per IP | 120 | 60 s |

Il limite di 40 invii scelta/minuto è superiore al ritmo massimo naturale imposto dal timer di 2 secondi, ma blocca burst e replay automatizzati senza penalizzare una partita normale.

### Area amministrativa

Per default `/admin/*` è raggiungibile soltanto da:

```text
127.0.0.1
::1
```

La allowlist è configurabile con:

```dotenv
ADMIN_ALLOWED_IPS=127.0.0.1,::1
```

Sono accettati anche CIDR supportati da Symfony `IpUtils`, per esempio una rete privata esplicita.

Questa protezione è intenzionalmente **default-deny**. Non è una sostituzione dell'autenticazione amministrativa: per un deployment remoto la milestone successiva deve introdurre identità, credenziali e autorizzazioni vere.

## Rate limiting

La tabella `request_rate_limit` contiene soltanto:

- scope;
- hash HMAC del soggetto;
- inizio finestra;
- contatore;
- timestamp ultimo aggiornamento.

Non contiene IP, cookie o token in chiaro.

Il contatore usa un UPSERT SQLite atomico. Le righe più vecchie di 48 ore vengono eliminate durante i normali consumi, senza richiedere un worker esterno.

Una richiesta bloccata restituisce:

```text
HTTP 429 Too Many Requests
Retry-After: <secondi>
```

## Header HTTP

Ogni risposta principale riceve:

- `Content-Security-Policy`;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy: no-referrer`;
- `Permissions-Policy` restrittiva;
- `Cross-Origin-Opener-Policy: same-origin`;
- `Cross-Origin-Resource-Policy: same-origin`;
- `X-Request-Id` generato dal server.

La CSP usa `script-src 'self'`: il JavaScript della giocata è stato spostato in `public/play.js`, quindi non è necessario `unsafe-inline` per gli script.

`style-src` mantiene temporaneamente `'unsafe-inline'` perché alcune viste esistenti usano attributi `style` e CSS embedded. È un debito tecnico esplicito, da eliminare durante la rifinitura UI.

In ambiente `prod`, su richieste HTTPS, viene aggiunto HSTS.

Le pagine `/gioca/*` e `/admin/*` sono marcate `no-store` per evitare caching di stato sensibile o amministrativo.

## Request ID e log strutturato

Ogni richiesta principale riceve un UUIDv7 server-side disponibile come:

```text
X-Request-Id
```

Gli eventi di sicurezza vengono scritti in:

```text
var/log/security.jsonl
```

Formato JSON Lines, una riga per evento.

Eventi iniziali:

- `ADMIN_ACCESS_DENIED`;
- `RATE_LIMITED`;
- `HTTP_EXCEPTION`.

La sanitizzazione difensiva oscura automaticamente chiavi che contengono riferimenti a:

- token;
- secret;
- password;
- nonce;
- cookie;
- authorization;
- challenge;
- winning/chosen path.

Gli IP non vengono registrati in chiaro negli eventi M1.7: viene usato soltanto un fingerprint HMAC abbreviato quando necessario.

Il logger non rilancia errori di I/O, per evitare che un problema secondario di logging nasconda l'errore applicativo originale.

## SQLite runtime hardening

All'avvio HTTP o console vengono applicati:

```sql
PRAGMA foreign_keys = ON;
PRAGMA busy_timeout = 5000;
PRAGMA synchronous = FULL;
PRAGMA journal_mode = WAL;       -- fuori da test
PRAGMA wal_autocheckpoint = 1000; -- fuori da test
```

Obiettivi:

- foreign key realmente attive per ogni connessione;
- attesa breve ma esplicita in caso di contesa tra writer;
- durabilità più conservativa per il prototipo;
- WAL per separare meglio letture e scritture concorrenti.

SQLite conserva comunque il vincolo fondamentale di un solo writer alla volta. Un aumento significativo della concorrenza richiede migrazione a PostgreSQL o altro database server.

## Health, readiness e diagnostica

Endpoint pubblici minimali:

```text
GET /health
GET /ready
```

`/health` verifica soltanto che il processo applicativo risponda.

`/ready` verifica che il database applicativo sia raggiungibile e che lo schema fondamentale sia interrogabile. Non espone path, stato dei round o dettagli interni.

Diagnostica amministrativa locale:

```text
GET /admin/diagnostica
php bin/console app:system:check
```

Controlli:

- `PRAGMA quick_check`;
- foreign keys;
- busy timeout;
- journal mode;
- synchronous;
- integrità completa della catena audit;
- path e dimensione DB;
- spazio libero;
- possibilità di scrivere il security log.

## Verifica della catena audit

`AuditIntegrityVerifier` controlla:

1. sequenza contigua a partire da 1;
2. `previous_hash` uguale all'hash dell'evento precedente;
3. ricalcolo di `event_hash` da tutti i campi persistiti.

La verifica è intenzionalmente completa e può diventare costosa con milioni di eventi. In una futura architettura server sarà opportuno aggiungere checkpoint/anchor periodici.

## Fault injection e concorrenza

M1.7 aggiunge test specifici per due casi critici.

### Failure durante la creazione del round successivo

Un trigger di test forza un errore durante il settlement vincente. Deve risultare:

- vecchio round ancora `ACTIVE`;
- ventesima scelta non registrata;
- giocata ancora a 19/20;
- nessun payout;
- nessun nuovo round.

Questo dimostra che la transazione di vincita non lascia stati intermedi.

### Richiesta stale dopo una vittoria concorrente

Una seconda sessione conserva un vecchio challenge aperto. Dopo la validazione del vincitore, l'invio stale deve essere rifiutato e non può:

- cambiare `winner_play_id`;
- creare un secondo payout;
- riaprire il vecchio round.

## Threat model sintetico

### Asset principali

- percorso vincente e nonce prima del settlement;
- stato monotono delle giocate;
- unicità del vincitore;
- ledger virtuale;
- ricevute e materiale commit-reveal;
- token di sessione e challenge;
- funzioni amministrative.

### Attaccanti considerati

- browser manipolato dal partecipante;
- script HTTP che tenta replay o brute force;
- utente remoto che scopre URL amministrativi;
- richieste concorrenti o duplicate;
- errore applicativo nel mezzo di una transazione;
- modifica accidentale del database o dei log.

### Attaccanti non completamente coperti

- amministratore del sistema operativo con pieno accesso a filesystem, processo e `APP_SECRET`;
- attacco distribuito su moltissimi IP;
- compromissione del reverse proxy o della macchina host;
- furto del browser/session cookie tramite malware locale;
- denial-of-service volumetrico prima che la richiesta raggiunga PHP.

## Limiti espliciti

1. L'area admin richiede autenticazione individuale e resta anche protetta da allowlist di rete.
2. La catena audit è locale e non ancorata a un servizio esterno.
3. Il rate limit è applicativo e SQLite-backed: non sostituisce un limite a reverse proxy/WAF.
4. La CSP usa `style-src 'self'`; CSS e JavaScript applicativi sono risorse esterne same-origin.
5. Il security log non ha ancora rotazione interna; va gestito a livello operativo.
6. SQLite resta adatto al prototipo, non a un carico elevato di writer concorrenti.

## Evoluzione M1.8

L'autenticazione amministrativa, la matrice ruoli, l'invalidazione sessioni e la CSP senza `unsafe-inline` sono descritte in `docs/13-admin-auth-e2e.md`.
