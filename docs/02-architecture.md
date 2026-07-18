# Architettura

## Moduli

- `Game/Domain`: percorso, commitment, montepremio virtuale e ciclo di vita del round.
- `Catalog/Domain`: coppie binarie, porta finale e snapshot immutabili.
- `Catalog/Application`: casi d'uso del catalogo amministrativo.
- `Catalog/Infrastructure`: repository DBAL e persistenza degli snapshot.
- `Game/Application`: apertura del round e query dei dati pubblici.
- `Game/Infrastructure`: cifratura autenticata dei segreti.
- `Verification/Application`: pubblicazione commit-reveal, ricevute e verifica pubblica.
- `Simulation/Domain`: profili sintetici, motore deterministico e metriche statistiche indipendenti dal framework.
- `Simulation/Application`: persistenza dei run, query aggregate e isolamento dal dominio reale.
- `Command`: simulazioni massive e diagnostica operativa CLI.
- `Security/Application`: throttling, log strutturato e diagnostica.
- `Security/Http`: guard amministrativa, rate limit e security header.
- `Infrastructure/Database`: configurazione runtime SQLite.
- `Controller`: adattatori HTTP Symfony.
- `migrations`: schema SQLite versionato e dati iniziali.

Il dominio non dipende da Symfony o Doctrine. I repository sono definiti come interfacce del dominio e implementati nell'infrastruttura con Doctrine DBAL.

## ULID

Le chiavi principali sono stringhe ULID da 26 caratteri. Non vengono esposti identificativi incrementali negli URL pubblici.

## Catalogo e snapshot

Il catalogo è modificabile, mentre il set di domande assegnato a un round è immutabile. `QuestionSetFactory` costruisce esattamente:

- 19 snapshot di coppie `REGULAR` attive;
- 1 snapshot `FINAL_DOOR` allo step 20.

`QuestionSetSnapshot` verifica ordine, unicità e completezza, poi calcola un hash SHA-256 della rappresentazione canonica. `DoctrineDbalQuestionSetSnapshotStore` consente una sola scrittura per round e soltanto nello stato `PREPARING`.

## Commitment

Il commitment usa SHA-256 sul payload canonico:

```text
twenty-choices-v1:<round-code>:<question-set-hash>:<20-bits>:<nonce-hex>
```

Il nonce contiene esattamente 32 byte casuali. Senza nonce, un attaccante potrebbe enumerare tutti i 1.048.576 percorsi.

## Apertura atomica del round

`OpenRound` orchestra catalogo, dominio, cifratura e ledger dentro una transazione DBAL. Il record nasce `PREPARING` e diventa `ACTIVE` soltanto quando sono presenti tutti i 20 snapshot e il seed virtuale.

## Cifratura a riposo

Percorso e nonce sono cifrati separatamente con una sottochiave derivata dal contesto del round. Sodium secretbox è il backend preferito; AES-256-GCM è il fallback. Il formato del ciphertext è versionato e autenticato.

La chiave radice deriva da `APP_SECRET`, generato localmente in `.env.local` dal bootstrap.

## SQLite

SQLite è adatto al prototipo. Le invarianti essenziali vengono replicate con indici univoci, vincoli e trigger:

- un solo round attivo;
- una sola porta finale;
- porta finale sempre attiva e di sistema;
- porta finale non eliminabile;
- tipo corretto rispetto alla posizione dello snapshot;
- snapshot non aggiornabili;
- materiale crittografico del round immutabile;
- ledger append-only e un solo seed per round;
- attivazione soltanto dopo snapshot e seed completi.

La chiusura del round usa già una singola transazione DBAL che comprende validazione, vittoria, payout, crediti, apertura del round successivo e pubblicazione del materiale commit-reveal. La migrazione a PostgreSQL rimane prevista prima di qualunque ambiente con forte concorrenza.

## Tempo

Tutti i timestamp persistiti sono UTC e immutabili. Il browser mostrerà il conto alla rovescia, ma l'accettazione userà esclusivamente `available_at` e l'orologio del server.

## Verificabilità pubblica

`PlayReceiptIssuer` crea una sola ricevuta per ogni giocata terminale. `PlayReceiptHasher` calcola l'hash canonico indipendente dal database. `RoundVerifier` ricostruisce il commitment esclusivamente da dati pubblici dopo il settlement.

`RoundVerificationPublisher` esiste per la compatibilità con round M1.4 già conclusi: decifra i segreti locali, verifica prima il commitment e solo in caso di corrispondenza li pubblica nelle nuove colonne immutabili.

La pagina `/verifica/{codice}` combina le due prove: integrità della ricevuta e coerenza dell'esito rispetto al materiale pubblico del round.


## Isolamento delle simulazioni

Il modulo `Simulation` non usa `StartPlay`, `SubmitChoice` o `ResolveCompletedPlay` e non decifra mai il percorso di un round reale. I run statistici scrivono esclusivamente in `simulation_run`, `simulation_choice_stat` e `simulation_path_stat`.

Il motore riutilizza la dimensione matematica definita da `WinningPath::COMBINATIONS`, ma usa un PRNG deterministico con seed perché lo scopo è riproducibilità scientifica, non sicurezza. Le primitive crittografiche del gioco reale restano separate.

La copertura usa un bitset fisso da 128 KiB. Questo evita un array da oltre un milione di elementi e rende prevedibile il consumo minimo di memoria per il tracciamento dei percorsi già osservati.

I dati di simulazione sono aggregati e immutabili. La dashboard può leggere metriche dal dominio reale, ma soltanto tramite query read-only.

## Sicurezza HTTP M1.7

`SecurityRequestSubscriber` opera dopo il routing e prima dei controller. Genera un UUIDv7 per la richiesta, applica il guard IP amministrativo e consuma i bucket del rate limiter. In risposta aggiunge CSP, header anti-framing, policy browser e cache-control restrittivo per gioco/admin.

Il rate limiter usa `request_rate_limit`, una tabella separata e mutabile per natura. Le chiavi sono HMAC-SHA-256 derivate da `APP_SECRET`; i soggetti originali non vengono persistiti.

`SecurityEventLogger` scrive JSON Lines in `var/log/security.jsonl` con lock di file e redazione difensiva dei campi sensibili.

## SQLite runtime

`SqliteRuntimeConfigurator` viene eseguito all’avvio delle richieste HTTP e dei comandi console. Abilita foreign key, busy timeout di 5 secondi e `synchronous=FULL`; fuori dai test abilita WAL e autocheckpoint.

Questa configurazione migliora robustezza e concorrenza del prototipo ma non cambia il modello single-writer di SQLite.

## Osservabilità

`/health` è un liveness probe senza dipendenza dal database. `/ready` è un readiness probe minimale che verifica l’accessibilità dello schema.

`SystemDiagnostics` e `app:system:check` verificano configurazione SQLite, `quick_check`, spazio libero, possibilità di scrittura del log e integrità completa dell’audit tramite `AuditIntegrityVerifier`.

## Failure atomici

I test M1.7 introducono fault injection nel punto di creazione del round successivo. Un errore deve causare rollback di tutto il settlement, inclusa la risposta 20/20. Un secondo test conserva un challenge stale di un’altra sessione e verifica che, dopo il settlement, non possa modificare il vincitore già registrato.

## M1.8 — Boundary di sicurezza amministrativo

Il flusso HTTP amministrativo è:

```text
Request
  → allowlist IP/CIDR
  → rate limit
  → autenticazione sessione + refresh account dal DB
  → AdminAccessPolicy
  → controller/caso d'uso
```

Componenti:

- `AdminAuthentication`: login, refresh della sessione, logout e invalidazione `auth_version`;
- `AdminUserRepository`: persistenza DBAL degli account;
- `AdminPasswordHasher`: policy e hashing password;
- `AdminAccessPolicy`: matrice di autorizzazione centralizzata deny-by-default;
- `SecurityRequestSubscriber`: applica allowlist, autenticazione, autorizzazione, rate limit, CSP e pagine errore.

La sessione non contiene privilegi autorevoli oltre la cache minima dell'identità: ad ogni richiesta l'account viene riletto dal database. Il ruolo effettivo e lo stato attivo sono quindi sempre quelli persistiti.

### Frontend/CSP

`templates/base.html.twig` non contiene più `<style>` inline. Tutto il CSS è in `public/app.css`; il gameplay continua a usare `public/play.js`. La CSP può quindi imporre:

```text
script-src 'self'
style-src 'self'
```

Gli elementi con valori grafici dinamici usano `<progress>` invece di attributi `style` generati dal template.
