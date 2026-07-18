# M1.8 — Autenticazione amministrativa, autorizzazioni ed E2E

## Obiettivo

M1.8 sostituisce il solo filtro di rete dell'area amministrativa con autenticazione individuale e autorizzazione esplicita. L'allowlist `ADMIN_ALLOWED_IPS` resta una difesa aggiuntiva, non una credenziale.

## Account amministrativi

Gli account sono persistiti in `admin_user` con:

- ULID interno;
- username normalizzato e univoco case-insensitive;
- password memorizzata esclusivamente come hash PHP (`Argon2id` quando disponibile, fallback sicuro di PHP);
- ruolo esplicito;
- flag attivo/disattivato;
- timestamp di creazione, modifica, ultimo accesso e cambio password;
- `auth_version` monotona per invalidare sessioni esistenti dopo modifiche di sicurezza.

La password deve avere almeno 12 caratteri, una lettera e una cifra. Il login restituisce sempre lo stesso errore generico per username inesistente, account disattivato o password errata.

Creazione del primo account:

```bash
php bin/console app:admin:create admin
```

Ruolo esplicito:

```bash
php bin/console app:admin:create auditor --role=AUDITOR
```

L'opzione `--password` esiste per automazione locale/test, ma è sconsigliata sui sistemi condivisi perché può finire nella cronologia della shell.

## Ruoli

### SUPER_ADMIN

Può accedere a tutte le funzioni amministrative, inclusa la gestione degli account.

### OPERATOR

Può:

- gestire il catalogo;
- aprire/consultare round;
- eseguire e consultare simulazioni.

Non può:

- gestire account amministrativi;
- accedere alla diagnostica di sicurezza.

### AUDITOR

Può:

- leggere diagnostica e integrità;
- consultare simulazioni e relativi CSV.

Non può modificare catalogo, aprire round, lanciare nuove simulazioni o gestire account.

La policy è deny-by-default: ogni route `/admin` non esplicitamente autorizzata viene negata.

## Protezione dell'ultimo SUPER_ADMIN

Trigger SQLite impediscono di:

- disattivare l'ultimo `SUPER_ADMIN` attivo;
- declassarlo a un altro ruolo;
- eliminarlo direttamente dal database.

L'interfaccia impedisce inoltre di disattivare l'account corrispondente alla sessione corrente.

## Sessione

La sessione Symfony usa un cookie dedicato:

```text
TWENTYCHOICESSESSID
```

Configurazione:

- `HttpOnly`;
- `SameSite=Lax`;
- `Secure=auto`;
- rigenerazione dell'ID al login;
- invalidazione completa al logout.

La sessione contiene soltanto id account, username, ruolo e `auth_version`. Ad ogni richiesta amministrativa l'account viene riletto dal database. Disattivazione, cambio password o variazione del ruolo incrementano `auth_version` e rendono immediatamente obsoleta la vecchia sessione.

## Difese login

- allowlist IP/CIDR applicata prima del login;
- CSRF obbligatorio;
- rate limit dedicato al login;
- verifica con dummy hash anche per username inesistente per ridurre differenze temporali evidenti;
- log strutturati `ADMIN_LOGIN_FAILED`, `ADMIN_LOGIN_SUCCEEDED`, `ADMIN_LOGOUT`, `ADMIN_AUTHORIZATION_DENIED`;
- nessuna password o username grezzo nei log di fallimento: viene scritto solo un fingerprint.

## CSP e UI

M1.8 elimina tutto il CSS inline dai template. La CSP è:

```text
script-src 'self'
style-src 'self'
```

senza `unsafe-inline`.

Il CSS è servito da `public/app.css`. Le barre dinamiche sono elementi `<progress>` invece di stili inline. Sono stati aggiunti:

- skip link;
- focus `:focus-visible` evidente;
- supporto `prefers-reduced-motion`;
- label e regioni live già presenti nel gameplay;
- navigazione amministrativa filtrata in base al ruolo.

## Pagine errore

Le risposte 403, 404, 429 e 500 usano una pagina coerente e non mostrano stack trace, path locali, query SQL o dettagli interni.

## Test E2E

La suite browser copre:

1. login/logout `SUPER_ADMIN`;
2. autorizzazioni `OPERATOR` e `AUDITOR`;
3. percorso browser completo 1/20 → 20/20 usando il percorso vincente reale cifrato del round di test;
4. settlement vincente tramite HTTP;
5. reset globale del round;
6. accredito di una seconda giocata rimasta aperta.

Nel test E2E il tempo non viene ridotto nell'applicazione: viene sostituito il servizio `SystemClock` con un `FrozenClock` e avanzato di due secondi tra visualizzazione e invio. Gli invarianti di produzione restano identici.

## Isolamento dei browser test (M1.8.1)

I test browser condividono la stessa istanza del kernel durante ciascun journey (`disableReboot`) per preservare test double come `FrozenClock`. Ogni test è inoltre racchiuso in una transazione DB esterna rollbackata incondizionatamente al termine. In questo modo settlement, nuovo round, account admin, rate limit e altri dati creati dagli E2E non possono contaminare test eseguiti successivamente.

`bin/phpunit` ricrea inoltre l'intero `var/test.db` all'inizio di ogni esecuzione come ulteriore barriera contro residui di suite precedentemente interrotte.



## SQLite runtime nei test transazionali (M1.8.2)

I browser test mantengono una transazione esterna per isolare completamente i dati creati durante il test. Prima di aprirla, `TransactionalWebTestCase` inizializza esplicitamente `SqliteRuntimeConfigurator`. Questo ordine è obbligatorio perché alcuni PRAGMA SQLite, in particolare `synchronous`, non possono essere modificati dopo `BEGIN`. Il configuratore è idempotente e non viene quindi rieseguito alla prima richiesta HTTP.
