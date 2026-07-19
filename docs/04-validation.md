# Validazione delle milestone

Data: 18 luglio 2026

## M0.2.1

- Configurazione compatibile con PHPUnit 9.6.35.
- Data provider eseguiti con annotazione `@dataProvider`.
- Suite completa confermata dall'utente.

## M1.1 — Verifiche eseguite nel pacchetto

- Tutti i file PHP superano `php -l` con PHP 8.4.
- Il runner indipendente dal framework esegue 8 controlli con 0 errori.
- Le due migrazioni contengono complessivamente 41 istruzioni `up()`.
- Le migrazioni sono state eseguite in sequenza su SQLite in memoria.
- Lo schema risultante contiene 9 tabelle, 20 indici espliciti e 5 trigger.
- Il seed contiene 44 coppie regolari attive e una porta finale.
- È stato verificato che SQLite rifiuti la disattivazione e l'eliminazione della porta finale.
- È stato verificato che lo step 20 rifiuti una coppia `REGULAR`.
- Gli snapshot di dominio richiedono 19 coppie regolari distinte più la porta finale.
- L'hash dello snapshot è deterministico.

## Verifiche nell'ambiente di sviluppo

Eseguire:

```powershell
./scripts/bootstrap.ps1
```

Lo script:

1. installa o aggiorna le dipendenze Composer;
2. applica le migrazioni al database di sviluppo;
3. applica le migrazioni al database di test;
4. esegue i test indipendenti dal framework;
5. esegue PHPUnit, inclusi i test di persistenza del catalogo.

## M1.1.1 — Correzioni di compatibilità

- Rimossa l'opzione `doctrine.dbal.use_savepoints`, non riconosciuta da DoctrineBundle 3.x.
- Dichiarato `KERNEL_CLASS=App\Kernel` in `phpunit.xml.dist` per i test basati su `KernelTestCase`.
- Fissata esplicitamente la linea PHPUnit `9.6` usata dal Symfony PHPUnit Bridge.
- Dichiarato `COMPOSER_BINARY=composer` prima del bridge per evitare il warning PHP su Windows durante la ricerca di `composer.phar`.
- Resi fail-fast gli script di bootstrap: il messaggio finale di successo non viene più mostrato dopo un comando fallito.
- Il database di test viene ricreato prima delle migrazioni, rendendo deterministici i test sul seed.


## M1.1.2 — Correzione parser PowerShell

- Corretto il messaggio di errore di `Invoke-Checked`: `$LASTEXITCODE:` veniva interpretato da PowerShell come riferimento a una variabile con scope.
- Il messaggio usa ora l'operatore di formato `-f`, compatibile con Windows PowerShell 5.1 e PowerShell 7.
- Verificato che non restino interpolazioni del tipo `$variabile:` negli script PowerShell del progetto.

## M1.1.3 — Compatibilità DoctrineBundle 3 / ORM 3

- Rimossa l'opzione obsoleta `doctrine.orm.auto_generate_proxy_classes`, non accettata da DoctrineBundle 3.x.
- Rimossa la sezione `dbname_suffix` per l'ambiente di test: SQLite usa già il database separato definito in `.env.test`.
- Il bootstrap installa Composer con `--no-scripts`, quindi esegue esplicitamente `cache:clear` come primo controllo del kernel.
- In caso di configurazione Symfony o Doctrine non valida, lo script si arresta prima di migrazioni e test con un errore chiaro.


## M1.1.4 — Controllo ambiente SQLite

- Il bootstrap Windows verifica che `PDO::getAvailableDrivers()` contenga `sqlite`.
- In caso contrario mostra `PHP_BINARY` e `php_ini_loaded_file()`.
- Composer e le migrazioni non vengono avviati finché il driver non è disponibile.
- Lo script Linux/macOS applica lo stesso controllo preventivo.

## M1.1.5 — Default URI

È stato aggiunto un valore predefinito per `DEFAULT_URI` sia nei file `.env` sia negli script di bootstrap. Questo evita errori di compilazione del container Symfony in ambienti locali che contengono una configurazione router ereditata da una versione precedente.


## M1.1.6 — Connessione SQLite esplicita

- Doctrine usa direttamente `driver: pdo_sqlite`.
- Il database di sviluppo è `var/data.db`.
- Il database di test è `var/test.db`.
- `DATABASE_URL` è stato rimosso da `.env` e `.env.test`.
- Variabili di sistema o file locali residui non possono più selezionare per errore un driver PostgreSQL, MySQL o altro.

## M1.1.7 — Baseline validata dall'utente

- Bootstrap PowerShell eseguito con successo mediante `-ExecutionPolicy Bypass`.
- 8 test di dominio, 0 errori.
- 25 test PHPUnit, 47 assertion, tutti superati.
- Reset SQLite test eseguito direttamente su `var/test.db`.
- Rilevamento Composer compatibile con installazione Scoop.

## M1.2 — Verifiche del pacchetto

- Tutti i file PHP superano `php -l` con PHP 8.4.
- `tools/domain-tests.php`: 10 test, 0 errori.
- Configurazioni YAML, JSON e XML valide.
- `scripts/bootstrap.sh` supera il controllo sintattico della shell.
- Le tre migrazioni sono state applicate in sequenza a SQLite in memoria.
- Schema risultante: 9 tabelle, 22 indici espliciti e 11 trigger.
- Seed catalogo: 44 coppie regolari e una porta finale.
- Simulata con successo l'apertura completa di un round con 20 snapshot e un `BANK_SEED`.
- Verificato che SQLite rifiuti la modifica del ledger.
- Verificato che SQLite rifiuti la modifica del commitment e dei segreti cifrati.
- Verificato il vincolo del singolo round attivo.
- Cifratura: round-trip, binding al contesto e rilevazione di manomissione verificati dal runner indipendente.
- Rimosso il segreto committato da `.env.dev`, che avrebbe avuto priorità sul valore generato in `.env.local`.
- Suite PHPUnit M1.2 predisposta: 36 casi effettivi. L'utente ha confermato il superamento completo della suite.


## M1.3 — Verifiche del pacchetto

- Tutti i file PHP superano `php -l` con PHP 8.4.
- `tools/domain-tests.php`: 12 test, 0 errori.
- Le quattro migrazioni sono state applicate in sequenza a SQLite in memoria.
- Schema risultante: 9 tabelle, 25 indici espliciti e 27 trigger.
- Smoke test SQLite completato con round, sessione, giocata, ledger, step e audit.
- Verificato che una risposta a 1,999 secondi venga rifiutata e quella a 2,000 secondi venga accettata.
- Verificato l'incremento del montepremio di 80 centesimi per una partecipazione standard.
- Verificata l'immutabilità strutturale dello step e l'avanzamento di un solo bit per transazione.
- Verificata la rotazione del token al refresh senza modifica di `shown_at` e `available_at`.
- Suite PHPUnit predisposta: 45 casi effettivi.

## M1.4 — Verifiche del pacchetto

- Tutti i file PHP superano `php -l` con PHP 8.4.
- `tools/domain-tests.php`: 12 test, 0 errori.
- Le cinque migrazioni sono state applicate in sequenza a SQLite in memoria.
- Schema risultante: 9 tabelle, 30 indici espliciti e 43 trigger.
- Verificata la protezione dei campi vincitore prima della transizione `ACTIVE → WON`.
- Eseguito uno scenario SQL completo: vincita, congelamento jackpot, payout, interruzione, emissione credito, settlement e riscatto credito.
- Il vecchio round termina `SETTLED` con un solo vincitore e un solo `JACKPOT_PAYOUT`.
- Una giocata aperta al momento della vincita termina `CREDITED` con un solo credito `AVAILABLE`.
- Il credito passa una sola volta a `REDEEMED` ed è associato a una nuova giocata `RESTART_CREDIT`.
- Il nuovo round parte da 1.000.000 centesimi virtuali e il riscatto del credito non incrementa `entry_contribution_cents`.
- La suite PHPUnit M1.4 aggiunge test end-to-end per percorso perdente, vincita/reset e riscatto credito; 49 casi effettivi.
- L’utente ha confermato il superamento completo della suite M1.4.


## M1.5 — Verificabilità pubblica

- Pubblicazione del percorso vincente e del nonce integrata nella transazione `WON → SETTLED`.
- Il commitment viene ricalcolato da soli dati pubblici tramite `RoundVerifier`.
- Compatibilità prevista per round M1.4 già conclusi: pubblicazione lazy soltanto dopo verifica crittografica riuscita.
- Introdotta `play_receipt`, una ricevuta immutabile e univoca per ogni giocata terminale.
- Hash ricevuta canonico legato a codice, round, partecipazione, esito, step, percorso e timestamp.
- Trigger SQLite impediscono modifica/eliminazione delle ricevute e modifica del materiale di verifica pubblicato.
- Aggiunti test unitari per ricalcolo commitment e hashing ricevuta.
- Aggiunti test di integrazione per ricevuta perdente pre-settlement, pubblicazione vincente, ricevute delle giocate interrotte e tentativi di manomissione.
- `tools/domain-tests.php`: 14 verifiche indipendenti, tutte superate nel pacchetto.
- 53 metodi di test PHPUnit, pari a **55 casi effettivi** considerando i 3 casi del data provider di `WinningPathTest`.
- Sei migrazioni eseguite in sequenza tramite harness SQLite: 10 tabelle applicative, 35 indici espliciti e 49 trigger.
- Smoke test SQLite M1.5: settlement senza reveal rifiutato; settlement con reveal accettato; modifica successiva di reveal e ricevuta rifiutata.


## M1.6 — Simulazione, statistiche e amministrazione

- Introdotto un motore di simulazione separato dalle tabelle operative del gioco.
- Il motore usa un bitset da 128 KiB per la copertura esatta dei 1.048.576 percorsi.
- Seed e parametri rendono riproducibili i run.
- Profili sintetici disponibili: uniforme, bias A costante e bias alternato.
- Persistenza separata in `simulation_run`, `simulation_choice_stat` e `simulation_path_stat`.
- Trigger SQLite rendono immutabili riepiloghi e statistiche persistite.
- Dashboard amministrativa con metriche aggregate del gioco reale in sola lettura.
- Esportazione CSV dei dati aggregati della simulazione.
- Comando CLI dedicato per simulazioni massive fino a 1.000.000 di giocate per run.
- Tutti i 94 file PHP sotto `src`, `tests` e `migrations` superano `php -l` con PHP 8.4.
- `tools/domain-tests.php`: 16 test, 0 errori.
- Le sette migrazioni sono state applicate in sequenza tramite harness SQLite.
- Schema risultante: 13 tabelle applicative, 40 indici espliciti e 55 trigger; catalogo invariato a 45 coppie.
- Smoke test SQLite: inserimento statistiche riuscito e modifica successiva di `simulation_run` respinta dal trigger di immutabilità.
- Smoke test motore con 100.000 giocate uniformi e seed `20260718`: 95.311 percorsi distinti e 4.689 giocate duplicate.
- Test indipendenti aggiunti per determinismo e concentrazione del bias.
- Test PHPUnit aggiunti per isolamento: una simulazione non modifica round, giocate o ledger.
- Suite predisposta: 58 metodi PHPUnit, pari a 60 casi effettivi considerando il data provider di `WinningPathTest`.
- La suite Symfony/PHPUnit completa deve essere confermata nell'ambiente dell'utente dopo l'applicazione della settima migrazione.

## M1.7 — Sicurezza, robustezza e osservabilità

- Tutti i 111 file PHP sotto `src`, `tests`, `migrations` e `tools` superano `php -l` con PHP 8.4.
- Configurazioni YAML, JSON e XML validate sintatticamente.
- Le otto migrazioni sono state applicate in sequenza tramite harness SQLite.
- Aggiunta ottava migrazione con tabella `request_rate_limit`.
- Schema atteso dopo tutte le migrazioni: 14 tabelle applicative, 42 indici espliciti e 55 trigger; catalogo invariato a 45 coppie.
- Il rate limiter usa UPSERT SQLite atomico e chiavi HMAC-SHA-256.
- Il browser non riceve JavaScript inline per il gameplay; `public/play.js` è compatibile con `script-src 'self'`.
- Tutte le risposte principali ricevono request ID e security header.
- `/admin/*` è consentito per default soltanto da loopback.
- `SqliteRuntimeConfigurator` applica `foreign_keys=ON`, `busy_timeout=5000`, `synchronous=FULL`; fuori dai test abilita WAL e autocheckpoint.
- `AuditIntegrityVerifier` ricalcola continuità e hash di tutti gli eventi.
- `SystemDiagnostics` espone controlli read-only da UI locale e CLI.
- Fault injection: un errore durante la creazione del round successivo deve lasciare round `ACTIVE`, giocata a 19/20 e zero payout.
- Concorrenza stale: una seconda richiesta conservata prima della vittoria non può sovrascrivere il vincitore o creare un secondo payout.
- `tools/domain-tests.php`: 18 verifiche indipendenti.
- Suite predisposta: 66 metodi PHPUnit, pari a 68 casi effettivi considerando il data provider di `WinningPathTest`.
- La suite Symfony/PHPUnit completa deve essere confermata nell’ambiente dell’utente dopo l’applicazione dell’ottava migrazione.

## M1.7.1 — Correzione gerarchia eccezioni

Correzione di compatibilità runtime: `DomainRuleViolation` non è più `final`, perché rappresenta la classe base delle violazioni di dominio specializzate. `ChoiceTooEarly` continua a estenderla e dispone ora di un test dedicato che carica entrambe le classi e verifica esplicitamente la gerarchia, evitando che il solo controllo `php -l` lasci passare una relazione di ereditarietà non valida.

Suite prevista dopo la correzione: **67 metodi PHPUnit / 69 casi effettivi** e **18 verifiche** nel runner indipendente.

## M1.7.2 — Correzione completamento atomico 20/20

Correzione di compatibilità DBAL/SQLite nel flusso `SubmitChoice`. La precedente UPDATE valorizzava `completed_at` tramite `CASE WHEN :completed = 1`, ma il confronto del parametro bindato poteva lasciare `completed_at` a `NULL` mentre `current_step` passava a 20. Il trigger `trg_play_validate_completion` rifiutava correttamente questo stato intermedio con `Play completion timestamp is inconsistent`.

La nuova implementazione passa direttamente `:completedAt`: `NULL` per gli step 1–19 e il timestamp server della risposta per lo step 20. L’avanzamento a 20, l’aggiunta del ventesimo bit e il timestamp di completamento avvengono nella stessa `UPDATE`.

Verifiche locali della correzione:

- sintassi PHP di `SubmitChoice.php`: valida;
- runner indipendente: 18 test, 0 failure;
- prova SQLite con lo stesso trigger di completamento: transizione 19/20 → 20/20 accettata atomicamente;
- gli 8 test PHPUnit precedentemente falliti condividono tutti il medesimo ramo di completamento corretto.

## M1.7.3 — Correzione mapping PlayScreen terminale

Correzione del ramo terminale di `OpenPlayStep`: nella chiamata posizionale al costruttore di `PlayScreen` mancava un valore `null`, per cui `verificationCode` veniva passato come ventunesimo argomento (`availableAt`) invece che come ventiduesimo. PHP rilevava correttamente il tipo incompatibile (`string` al posto di `?DateTimeImmutable`).

Entrambe le costruzioni di `PlayScreen` usano ora argomenti nominati. Questo elimina la dipendenza dall'ordine posizionale dei 22 parametri e rende più sicure future estensioni del DTO. Il test `PlayFlowTest::testItRecordsAllTwentyChoicesWithoutCreatingTheNextStepEarly` verifica inoltre che, a giocata terminale, `availableAt` sia `NULL` e `verificationCode` sia valorizzato.


## M1.8 — Autenticazione amministrativa, autorizzazioni, CSP ed E2E

- Nona migrazione aggiunta con tabella `admin_user`.
- Sequenza completa M0.2 → M1.8 applicata tramite harness SQLite: **15 tabelle applicative, 44 indici espliciti e 59 trigger**, catalogo invariato a 45 coppie.
- Verificato il trigger che impedisce declassamento/disattivazione dell'ultimo `SUPER_ADMIN` attivo.
- Password amministrative hashate con API native PHP; policy minima 12 caratteri, una lettera e una cifra.
- Sessione dedicata `TWENTYCHOICESSESSID`, rigenerazione ID al login e invalidazione al logout.
- `auth_version` invalida sessioni esistenti dopo cambio password, ruolo o stato account.
- Matrice ruoli centralizzata deny-by-default per `SUPER_ADMIN`, `OPERATOR`, `AUDITOR`.
- CSP aggiornata a `style-src 'self'`; nessun `style=` o `<style>` residuo nei template.
- CSS spostato in `public/app.css`; progress dinamici convertiti a elementi HTML `<progress>`.
- Pagine generiche 403/404/429/500 aggiunte senza dettagli interni.
- Test browser aggiunti per login/logout, autorizzazioni di ruolo e percorso completo vincente 1/20 → 20/20 con reset globale e credito di una giocata concorrente.
- `tools/domain-tests.php`: **20 verifiche indipendenti**, tutte superate nel pacchetto.
- Suite predisposta: **79 metodi PHPUnit**, pari a **81 casi effettivi** considerando i 3 casi del data provider `WinningPathTest`.
- Tutti i file PHP sotto `src`, `tests`, `migrations` e `tools` superano `php -l` con PHP 8.4 nell'ambiente di generazione.
- La suite Symfony/PHPUnit completa deve essere confermata nell'ambiente dell'utente dopo l'applicazione della nona migrazione.

## M1.8.1 — Isolamento E2E e database test deterministico

Correzione successiva alla prima esecuzione completa M1.8 nell'ambiente Windows dell'utente. La suite evidenziava due problemi distinti:

- `PlayJourneyE2ETest` sostituiva `SystemClock` con `FrozenClock`, ma il reboot del kernel tra richieste ricreava i servizi e faceva perdere il clock controllato; la giocata browser rimaneva quindi allo step 1 invece di completare il percorso vincente;
- l'E2E lasciava nel database test il nuovo round `ACTIVE` creato dal settlement. Poiché `tests/EndToEnd` viene eseguito prima di `tests/Game`, i test applicativi successivi trovavano un round già attivo e fallivano con `An active round already exists`.

Correzioni:

- introdotto `TransactionalWebTestCase`, che usa `KernelBrowser::disableReboot()` e avvolge ciascun browser test in una transazione esterna sempre rollbackata in `tearDown`;
- applicato l'isolamento a `PlayJourneyE2ETest`, `AdminAuthenticationE2ETest` e `SecurityHttpTest`;
- il percorso E2E verifica ora dopo ogni POST che `current_step` sia avanzato esattamente di uno, intercettando immediatamente regressioni del clock/timer;
- `bin/phpunit` elimina `var/test.db`, `var/test.db-wal` e `var/test.db-shm` e riapplica le migrazioni `--env=test` prima di ogni esecuzione, rendendo deterministico anche un semplice `php bin/phpunit` dopo una precedente suite interrotta;
- gli script di bootstrap delegano a `bin/phpunit` la preparazione del database test, evitando doppio reset/migrazione.

La revisione non modifica schema, regole di gioco, algoritmo di settlement o sicurezza runtime.



## M1.8.2 — SQLite runtime prima della transazione E2E

La prima esecuzione completa di M1.8.1 su Windows ha evidenziato che i browser test aprivano la transazione esterna prima della prima richiesta HTTP. `SqliteRuntimeConfigurator`, eseguito sul primo `kernel.request`, tentava quindi di applicare `PRAGMA synchronous = FULL` mentre la connessione era già in transazione. SQLite non consente di cambiare il livello `synchronous` dentro una transazione: la richiesta terminava con HTTP 500, il DOM risultava vuoto e i test di sicurezza ricevevano 500 al posto degli status attesi.

Correzione:

- `TransactionalWebTestCase::createTransactionalClient()` esegue esplicitamente `SqliteRuntimeConfigurator::configure()` subito dopo il boot del kernel e **prima** di `beginTransaction()`;
- il configuratore resta idempotente tramite il flag interno `configured`, quindi il subscriber della prima richiesta non riapplica i PRAGMA;
- la transazione esterna continua a garantire il rollback completo dei dati creati dagli E2E;
- nessuna regola di gioco, schema dati o configurazione di produzione viene modificata.

Questa correzione copre la causa comune dei 5 errori DOM vuoto e dei 4 fallimenti HTTP 500 osservati nella suite M1.8.1.

## Pianificazione M1.9 — Verification & Hardening

Prima di riprendere nuove funzionalità M2.1 è stata formalizzata una fase di verifica progressiva dell'intero processo, suddivisa in 15 milestone bloccanti da M1.9.1 a M1.9.15.

La fase copre, nell'ordine: ambiente/database, catalogo e apertura round, commitment, avvio giocata e contabilità, timer/anti-replay, journey perdente, settlement vincente, concorrenza, reset/crediti, ricevute/verifica pubblica, ledger/audit, amministrazione/sicurezza HTTP, fault injection/recovery, performance/limiti SQLite e full journey finale.

Ogni milestone richiede checklist manuale, test automatici, risultati osservati, correzioni, documentazione, ZIP completo e validazione esplicita. Il piano dettagliato e i gate di accettazione sono in `docs/15-verification-hardening-plan.md`.

## M1.9.1 — Environment & Database Verification

Prima milestone della fase Verification & Hardening, applicata alla baseline M1.8.2 senza modificare regole di gioco o schema applicativo.

Rafforzamenti introdotti:

- preflight condiviso `tools/bootstrap-preflight.php` per PHP >= 8.3, estensioni obbligatorie, PDO SQLite realmente operativo, backend crittografico e filesystem runtime;
- `tools/package-audit.php` per verificare che una distribuzione pulita non contenga `.env.local`, database, sidecar SQLite, `vendor/` o altri file runtime;
- nuovo comando `app:installation:verify` per path DB, separazione dev/test, `quick_check`, PRAGMA, migrazioni applicate, seed catalogo e secret applicativo;
- `TestDatabaseReset` estratto dal wrapper PHPUnit e coperto da test dedicato;
- script `verify-m1.9.1.ps1` e `verify-m1.9.1.sh`, che da estrazione pulita eseguono package audit, due bootstrap consecutivi e verifica finale;
- CI rafforzata con package audit, preflight e doppia applicazione consecutiva delle migrazioni test per verificare esplicitamente l'idempotenza.

Test aggiunti: **5 metodi PHPUnit**. Totale predisposto M1.9.1: **84 metodi PHPUnit / 86 casi effettivi**, più **20 verifiche** nel runner indipendente.

Risultati nell'ambiente di preparazione:

- lint PHP dei file nuovi/modificati: **OK**;
- package audit: **OK**;
- runner indipendente: **20/20 passati**;
- il preflight ha correttamente bloccato il runtime di preparazione perché privo di `pdo_sqlite`.

La suite Symfony/PHPUnit completa non viene dichiarata eseguita nell'ambiente di preparazione, che non dispone di `pdo_sqlite` né Composer. Il gate resta quindi **in attesa della prova su estrazione pulita nell'ambiente dell'utente**.

Checklist, comandi, evidenze e gate: `docs/16-m1.9.1-environment-database-verification.md`.
