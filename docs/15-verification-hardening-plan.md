# M1.9 — Verification & Hardening Plan

> Stato corrente: **milestone validate fino a M1.9.7.1; M1.9.8 Concurrency & Single-Winner Verification implementata e in attesa di validazione**. Evidenze più recenti: `docs/27-m1.9.7-winning-settlement-verification.md`, `docs/28-m1.9.7.1-late-fault-audit-baseline-hotfix.md` e `docs/29-m1.9.8-concurrency-single-winner-verification.md`.

## Obiettivo

La fase M1.9 interrompe temporaneamente lo sviluppo di nuove funzionalità e verifica l'intero sistema TwentyChoices in modo progressivo, osservabile e ripetibile.

L'obiettivo non è soltanto ottenere una suite verde, ma dimostrare che ogni anello del processo funziona isolatamente e in combinazione:

- bootstrap e configurazione;
- database e migrazioni;
- catalogo e snapshot;
- apertura del round;
- commitment crittografico;
- avvio della giocata e contabilità virtuale;
- timer, token monouso e anti-replay;
- percorso perdente;
- vincita e settlement atomico;
- concorrenza e singolo vincitore;
- reset globale e crediti;
- ricevute e verifica pubblica;
- ledger e audit;
- autenticazione, autorizzazioni e sicurezza HTTP;
- fault injection, recovery, prestazioni e full journey finale.

Prerequisito: la baseline M1.8.x deve prima tornare completamente verde nella suite automatica. M2.1 resta sospesa fino al completamento e alla validazione di M1.9.15.

## Metodo di lavoro comune a ogni milestone

Ogni milestone M1.9.x deve produrre:

1. checklist manuale ripetibile;
2. test automatici nuovi o rafforzati dove esistono gap;
3. risultati osservati e dati diagnostici rilevanti;
4. elenco degli eventuali bug trovati;
5. correzioni applicate senza ampliare il perimetro funzionale;
6. documentazione aggiornata;
7. ZIP completo del progetto;
8. validazione esplicita prima di passare alla milestone successiva.

Ogni milestone ha un **gate**: se il gate non è soddisfatto, non si procede alla successiva.

---

## M1.9.1 — Environment & Database Verification

### Scopo

Dimostrare che un'installazione completamente pulita è riproducibile e che ambiente, segreti, SQLite e test sono configurati correttamente.

### Verifiche

- estrazione dello ZIP in una cartella vuota;
- `composer install` senza dipendenze implicite dal computer di sviluppo;
- generazione corretta di `.env.local` e dei segreti locali;
- nessun segreto, database o file runtime incluso nel pacchetto;
- driver `pdo_sqlite` realmente disponibile al PHP CLI usato dal progetto;
- creazione separata di database `dev` e `test`;
- applicazione completa di tutte le migrazioni da zero;
- seed del catalogo coerente;
- PRAGMA SQLite verificati con `app:system:check`;
- bootstrap eseguibile due volte senza corrompere lo stato;
- `php bin/phpunit` sempre avviato da database test pulito;
- comportamento coerente su Windows PowerShell e script Unix.

### Test da automatizzare o rafforzare

- smoke test di bootstrap/configurazione;
- verifica delle estensioni PHP obbligatorie;
- verifica che il DB di test venga ricreato deterministically;
- test di idempotenza delle migrazioni già applicate.

### Gate

**Installazione da zero completamente automatica, ripetibile e senza interventi manuali nascosti.**

---

## M1.9.2 — Catalog & Round Creation Verification

### Scopo

Verificare catalogo, invarianti editoriali, snapshot immutabili e apertura completa del round.

### Catalogo

Provare manualmente:

- creazione coppia;
- modifica coppia;
- disattivazione e riattivazione;
- eliminazione dove consentita;
- categorie e ordinamento.

Verificare gli invarianti:

- una sola porta finale;
- porta finale non eliminabile;
- porta finale non disattivabile;
- almeno 19 coppie regolari attive;
- un round contiene esattamente 19 coppie regolari più la porta finale;
- nessuna coppia regolare duplicata nello stesso round.

### Snapshot

Scenario obbligatorio:

1. aprire un round;
2. modificare una coppia del catalogo già selezionata;
3. verificare che il round continui a usare lo snapshot originale.

### Apertura round

Controllare:

- codice pubblico;
- stato `ACTIVE`;
- 20 snapshot;
- `BANK_SEED` da 10.000,00 € virtuali;
- commitment presente;
- percorso e nonce cifrati;
- impossibilità di aprire un secondo round `ACTIVE`.

### Gate

**Catalogo modificabile senza effetti retroattivi e apertura di un solo round globale completo, coerente e immutabile.**

### Implementazione M1.9.2

La verifica è automatizzata anche dal comando transazionale `app:verification:catalog-round`, che esercita CRUD, limite delle 19 coppie, protezione della porta finale, apertura del round, immutabilità degli snapshot, eliminazione di una coppia già snapshot-tata e rifiuto del secondo round `ACTIVE`. Tutte le mutazioni dello scenario vengono rollbackate.

Dettagli, bug corretti e checklist: `docs/17-m1.9.2-catalog-round-verification.md`.

---

## M1.9.2.1 — Runtime Compatibility & Monotonic Timing Hardening

### Scopo

Chiudere due incoerenze individuate dall'audit prima di proseguire: baseline PHP dichiarata non coerente con il lock Composer e countdown browser dipendente dal clock civile del client.

### Correzioni obbligatorie

- PHP 8.4 come baseline ufficiale coerente in `composer.json`, lock, README, bootstrap, preflight e CI;
- `config.platform.php = 8.4.0` e relativo `platform-overrides` nel lock per mantenere la risoluzione dipendenze compatibile con la baseline minima;
- `composer check-platform-reqs` nel bootstrap e in CI;
- controllo automatico di coerenza della baseline distribuita e freschezza del content hash del lock;
- eliminazione degli epoch assoluti server dal DOM del gioco;
- countdown basato su millisecondi relativi server + `performance.now()` monotono;
- test anti-regressione su refresh, scadenza timer e assenza di `Date.now()` nel flusso di scelta.

### Gate

**Installazione pulita su PHP 8.4+, platform requirements Composer verdi, suite completa verde, gate M1.9.2 verde e timer client indipendente dall'orologio civile locale.**

Dettagli: `docs/18-m1.9.2.1-runtime-timing-hardening.md`.

---

## M1.9.3 — Cryptographic Commitment Verification

### Scopo

Dimostrare end-to-end la correttezza del commit-reveal e l'impossibilità di modificare retroattivamente la strada senza rilevazione.

### Verifiche

Ricalcolare il commitment a partire da:

- codice round;
- hash del set di domande;
- percorso di 20 bit;
- nonce.

Verificare il caso corretto e quattro manomissioni indipendenti:

- cambio di un bit del percorso;
- cambio di un byte del nonce;
- cambio del codice round;
- cambio dello snapshot/hash delle domande.

Ogni manomissione deve produrre un commitment differente.

Durante un round `ACTIVE` verificare che percorso e nonce non siano disponibili in endpoint pubblici, DOM, log o payload browser.

### Gate

**Il commitment originale è riproducibile solo con il materiale corretto e ogni manomissione è rilevabile.**

### Implementazione M1.9.3

Il comando transazionale `app:verification:cryptographic-commitment` apre un round reale, ricostruisce il commitment dai segreti decifrati internamente, prova separatamente le quattro manomissioni obbligatorie, verifica binding/autenticazione dei ciphertext, immutabilità SQLite e assenza dei segreti dai canali runtime durante `ACTIVE`. Un test HTTP E2E controlla inoltre body/header/DOM dei principali endpoint pubblici. Tutto lo scenario DB viene rollbackato.

Dettagli e checklist: `docs/22-m1.9.3-cryptographic-commitment-verification.md`.

---

## M1.9.4 — Play Start & Accounting Verification

### Scopo

Verificare creazione della sessione anonima, apertura della giocata e contabilizzazione virtuale iniziale.

### Verifiche

Flusso:

`Home → Inizia → sessione anonima → play → scelta 1/20`

Controllare:

- codice giocata casuale e non progressivo;
- numero di partecipazione;
- token di sessione casuale;
- solo hash del token persistito;
- cookie con attributi corretti;
- una sola giocata aperta per sessione/round;
- doppio click o doppio POST non crea una seconda quota;
- refresh riprende la stessa giocata.

Per una partecipazione standard verificare esattamente:

- 1,00 € virtuale di ingresso;
- 0,80 € virtuali al jackpot;
- 0,20 € virtuali all'organizzazione.

### Gate

**Una partecipazione produce una sola giocata e una sola contabilizzazione 100/80/20.**

---

## M1.9.5 — Step, Timer & Anti-Replay Verification

### Scopo

Verificare in profondità la macchina a stati di una singola scelta e tutti i principali tentativi di bypass.

### Singolo step

Controllare:

- `shown_at`;
- `available_at`;
- `answered_at`;
- token challenge monouso;
- un solo bit aggiunto al percorso;
- incremento esatto di uno step.

Test temporali obbligatori:

- risposta a 1,999 secondi: rifiutata;
- risposta a 2,000 secondi: accettata.

### Refresh e replay

Verificare:

- refresh non azzera il timer;
- token precedente invalidato dopo rotazione;
- replay della stessa richiesta non duplica la scelta;
- due schede sullo stesso step: solo la prima risposta valida avanza lo stato.

### Manipolazioni HTTP

Tentare di inviare valori client non autorevoli:

- step arbitrario, incluso 20;
- round differente;
- play differente;
- opzione non valida;
- percorso completo;
- timestamp falsificato.

Il server deve ignorare o rifiutare tali valori e derivare lo stato dal database.

### Gate

**Il client non può anticipare, duplicare, saltare o controllare la progressione della giocata.**

---

## M1.9.6 — Full Losing Journey Verification

### Scopo

Verificare un percorso completo di 20 scelte che non corrisponde alla strada segreta.

### Verifiche

Dopo la ventesima scelta perdente controllare:

- `play.status = COMPLETED_LOST`;
- round ancora `ACTIVE`;
- `completed_at` valorizzato;
- esattamente 20 scelte persistite;
- percorso di esattamente 20 bit;
- ricevuta di verifica emessa;
- nessuna pubblicazione anticipata di percorso segreto o nonce;
- possibilità di iniziare una nuova partecipazione nello stesso round.

### Gate

**Una perdita chiude soltanto la singola giocata e non altera il ciclo di vita del round.**

---

## M1.9.7 — Winning Settlement Verification

### Scopo

Verificare l'intera transazione vincente e dimostrare l'assenza di stati intermedi osservabili.

### Scenario

Completare una giocata con percorso esattamente uguale alla strada segreta.

Alla scelta 20 verificare atomicamente:

1. registrazione della risposta finale;
2. ricostruzione del percorso;
3. validazione crittografica;
4. claim `ACTIVE → WON`;
5. `winner_play_id` unico;
6. congelamento jackpot;
7. `JACKPOT_PAYOUT` unico;
8. interruzione delle altre giocate;
9. emissione crediti;
10. creazione del nuovo round da 10.000,00 €;
11. reveal di percorso e nonce;
12. settlement del vecchio round;
13. emissione delle ricevute terminali.

Verificare che il jackpot congelato sia esattamente:

`10.000,00 € + 0,80 € × partecipazioni standard contabilizzate nel round`

### Gate

**Il settlement è una singola unità atomica: o avviene tutto oppure non avviene nulla.**

---

## M1.9.8 — Concurrency & Single-Winner Verification

### Scopo

Dimostrare che due richieste concorrenti non possono produrre due vincitori, due payout o due nuovi round.

### Scenario principale

Due giocatori inviano quasi contemporaneamente una scelta 20 corretta.

Risultato obbligatorio:

- un solo vincitore;
- un solo `winner_play_id`;
- un solo payout;
- un solo nuovo round;
- il secondo giocatore gestito secondo lo stato ormai chiuso del round;
- nessuna sovrascrittura del vincitore.

Ripetere il test più volte e includere una richiesta stale aperta prima della vittoria.

### Gate

**L'unicità del vincitore è garantita dal database e dalla transazione, non dal timing del browser.**

---

## M1.9.9 — Reset & Restart Credit Verification

### Scopo

Verificare il reset globale delle partite aperte e il ciclo completo dei crediti di ripartenza.

### Scenario

Al momento della vittoria preparare giocatori a step differenti, ad esempio:

- vincitore a 20/20;
- altro giocatore a 18/20;
- altro a 7/20;
- altro appena iniziato.

Dopo il settlement verificare:

- vincitore `COMPLETED_WON`;
- tutte le altre giocate aperte terminali/interrotte secondo il modello e accreditate;
- un solo credito `AVAILABLE` per ogni giocata interrotta;
- token e URL del vecchio round non più utilizzabili.

### Riscatto

Per ogni credito:

- creare una nuova giocata nel nuovo round;
- passare credito `AVAILABLE → REDEEMED`;
- nessun nuovo contributo da 0,80 €;
- nessuna nuova quota organizzatore da 0,20 €;
- impossibilità di riutilizzare lo stesso credito.

### Gate

**Ogni giocata interrotta riceve esattamente un credito monouso, contabilmente neutro nel nuovo round.**

---

## M1.9.10 — Receipt & Public Verification Audit

### Scopo

Verificare che ogni esito terminale sia dimostrabile pubblicamente senza fidarsi dell'amministratore.

### Tipi di ricevuta

Verificare ricevute per:

- `WON`;
- `LOST`;
- `INTERRUPTED/CREDITED` secondo lo stato persistito.

Controllare:

- codice pubblico univoco;
- snapshot coerente con la giocata;
- hash ricevuta valido;
- immutabilità a livello DB;
- ricevuta inventata/non esistente restituisce risposta appropriata.

### Prima e dopo il settlement

Prima della chiusura del round una ricevuta perdente può essere valida ma il reveal del round deve risultare ancora in attesa.

Dopo il settlement verificare insieme:

- ricevuta;
- percorso vincente;
- nonce;
- commitment iniziale;
- commitment ricalcolato;
- coerenza dell'esito.

### Gate

**Le prove pubbliche ricostruiscono in modo indipendente integrità del round e risultato della singola partecipazione.**

---

## M1.9.11 — Ledger & Audit Integrity Verification

### Scopo

Eseguire una riconciliazione contabile indipendente e verificare la catena hash dell'audit.

### Ledger

Controllare almeno:

- `BANK_SEED`;
- ingresso partecipazione;
- `JACKPOT_CONTRIBUTION`;
- quota organizzatore;
- `JACKPOT_PAYOUT`;
- crediti e loro riscatto.

Verificare al centesimo:

`1,00 = 0,80 + 0,20`

E per un round:

`jackpot = 10.000,00 + contributi standard validi`

Tentare `UPDATE` e `DELETE` sul ledger: entrambi devono essere rifiutati.

### Audit

Ricostruire una giocata completa dagli eventi e verificare:

- `sequence_number`;
- `previous_hash`;
- `event_hash`;
- correlazione con round e play.

Su una copia di test manomettere un evento e verificare che il controllo individui il primo elemento non valido.

### Gate

**Contabilità riconciliabile al centesimo e modifiche storiche rilevabili.**

---

## M1.9.12 — Admin, Authorization & HTTP Security Verification

### Scopo

Verificare autenticazione amministrativa, matrice dei ruoli, revoca sessioni e hardening HTTP/browser.

### Ruoli

Verificare esplicitamente:

- `SUPER_ADMIN`: accesso completo;
- `OPERATOR`: operazioni consentite, niente gestione utenti/diagnostica riservata;
- `AUDITOR`: sola lettura nelle aree previste, nessuna mutazione.

### Account e sessioni

Provare:

- login corretto e login errato;
- rate limit del login;
- logout;
- cambio password;
- cambio ruolo;
- disattivazione utente;
- invalidazione sessione tramite `auth_version`;
- protezione dell'ultimo `SUPER_ADMIN`;
- accesso da IP non ammesso.

### HTTP/browser

Verificare con test e DevTools:

- CSP;
- HSTS quando applicabile;
- `X-Frame-Options`;
- `X-Content-Type-Options`;
- `Referrer-Policy`;
- `Permissions-Policy`;
- COOP/CORP;
- nessun JS/CSS inline non previsto;
- nessun segreto nel DOM o nelle richieste;
- cookie corretti;
- pagine 403/404/429/500 senza dettagli interni.

### Gate

**Nessuna escalation di privilegi e nessuna informazione critica esposta inutilmente al browser o ai log.**

---

## M1.9.13 — Fault Injection & Recovery

### Scopo

Verificare che errori artificiali in punti critici producano rollback totale o uno stato esplicitamente recuperabile.

### Fault injection

Introdurre errori controllati:

- dopo la scelta 20;
- dopo il claim del vincitore;
- durante il payout;
- durante la creazione crediti;
- durante la creazione del nuovo round;
- durante il reveal.

Caso fondamentale:

se la creazione del nuovo round fallisce durante la transazione vincente, verificare:

- vecchio round ancora `ACTIVE`;
- nessun vincitore persistito;
- nessun payout;
- nessun credito parziale;
- scelta vincente non consumata definitivamente.

### Crash/restart SQLite

Simulare:

- arresto e riavvio processo PHP;
- riavvio con round attivo;
- ripresa di una giocata;
- WAL/checkpoint;
- `quick_check`;
- foreign key;
- lock e busy timeout;
- più processi PHP concorrenti.

### Gate

**Nessun mezzo settlement e nessuna corruzione persistente dopo errori o restart.**

---

## M1.9.14 — Performance, SQLite Limits & Simulation Isolation

### Scopo

Misurare i limiti reali del prototipo e documentare quando SQLite non è più adeguato.

### Carico progressivo

Simulare workload equivalenti a:

- 10 giocatori;
- 100;
- 1.000;
- 10.000.

Misurare:

- latenza apertura step;
- latenza submit scelta;
- attese lock SQLite;
- durata settlement;
- crescita DB;
- crescita WAL;
- crescita audit;
- errori `busy`/timeout.

### Simulatore vs gioco reale

Confrontare metriche aggregate e verificare che una simulazione non modifichi mai:

- round;
- giocate;
- ledger;
- jackpot;
- audit operativo.

Eseguire snapshot DB prima/dopo una simulazione.

### Gate

**Limiti operativi misurati e documentati; isolamento assoluto del simulatore; criteri chiari per una futura migrazione a PostgreSQL.**

---

## M1.9.15 — Final Full-Journey Acceptance

### Scopo

Eseguire una acceptance test manuale e automatizzata dell'intero sistema partendo da un ambiente pulito.

### Scenario canonico

1. installazione pulita;
2. creazione `SUPER_ADMIN`;
3. login;
4. verifica catalogo;
5. apertura round R1;
6. giocatore A entra;
7. giocatore B entra;
8. A completa un percorso perdente;
9. A rigioca;
10. giocatore C entra;
11. B prosegue;
12. A completa il percorso vincente;
13. R1 passa a `SETTLED`;
14. B e C ricevono credito;
15. R2 nasce con 10.000,00 € virtuali;
16. B riscatta il credito;
17. il jackpot R2 rimane invariato dal riscatto;
18. verifica ricevuta vincente;
19. verifica commitment R1;
20. controllo storico pubblico;
21. riconciliazione ledger;
22. verifica audit;
23. diagnostica finale;
24. restart applicazione e nuova verifica di consistenza.

### Evidenze da conservare

- output bootstrap;
- output suite completa;
- codici round e ricevute usati nella prova;
- snapshot delle metriche principali;
- risultato `app:system:check`;
- riconciliazione contabile;
- esito integrità audit;
- eventuali anomalie residue esplicitamente documentate.

### Gate finale

**L'intero viaggio funziona da installazione pulita a settlement, verifica pubblica, credito, nuovo round e restart senza incoerenze. Solo dopo questo gate si riapre M2.1.**

---

## Sequenza ufficiale

La fase deve essere eseguita nell'ordine seguente:

1. M1.9.1 — Environment & Database Verification
2. M1.9.2 — Catalog & Round Creation Verification
3. M1.9.3 — Cryptographic Commitment Verification
4. M1.9.4 — Play Start & Accounting Verification
5. M1.9.5 — Step, Timer & Anti-Replay Verification
6. M1.9.6 — Full Losing Journey Verification
7. M1.9.7 — Winning Settlement Verification
8. M1.9.8 — Concurrency & Single-Winner Verification
9. M1.9.9 — Reset & Restart Credit Verification
10. M1.9.10 — Receipt & Public Verification Audit
11. M1.9.11 — Ledger & Audit Integrity Verification
12. M1.9.12 — Admin, Authorization & HTTP Security Verification
13. M1.9.13 — Fault Injection & Recovery
14. M1.9.14 — Performance, SQLite Limits & Simulation Isolation
15. M1.9.15 — Final Full-Journey Acceptance

Ogni milestone è bloccante per la successiva.

## Correzione M1.9.2.1.1 — working-copy verification

Il gate installabile usa un release manifest SHA-256 per distinguere i sorgenti distribuiti dagli artefatti runtime generati dopo il bootstrap. `tools/package-audit.php` resta rigoroso e dedicato al tree pulito in fase di confezionamento.


## Correzione M1.9.2.1.2 — Symfony PHPUnit Bridge generated tooling

`bin/.phpunit/` è una directory runtime creata automaticamente dal Symfony PHPUnit Bridge dopo l'installazione. Non fa parte dei sorgenti di release: il manifest checker la ignora nelle working copy inizializzate, mentre package audit e manifest continuano a vietarne la distribuzione nello ZIP.


## Correzione M1.9.2.1.3 — snapshot live-reference detachment

La suite completa ha rilevato che `ON DELETE SET NULL` non è sufficiente come unica garanzia perché `PRAGMA foreign_keys` è connection-local. La migration `Version20260719000200` aggiunge un trigger SQLite atomico che stacca `round_question.choice_pair_id` prima della cancellazione di una coppia regolare, preservando `choice_pair_source_id_snapshot` e gli altri dati immutabili.

## Stato implementazione M1.9.6 — Full Losing Journey Verification

Baseline di partenza: **M1.9.5 validata integralmente dall'utente**.

M1.9.6 aggiunge un gate transazionale e un E2E browser completi per il percorso perdente. Il percorso di prova diverge dal segreto fin dalla prima scelta ma deve comunque raggiungere 20/20, dimostrando che non esiste terminazione anticipata. Dopo la perdita vengono verificati `COMPLETED_LOST`, 20 step persistiti, ricevuta integra, round ancora `ACTIVE`, assenza di winner/payout/crediti/nuovo round/reveal e possibilità di una nuova partecipazione nello stesso round.

Gate: `scripts/verify-m1.9.6.ps1/.sh`.


## Stato implementazione M1.9.7 — Winning Settlement Verification

Baseline di partenza: **M1.9.6 validata integralmente dall'utente**.

M1.9.7 aggiunge un gate transazionale completo sul percorso vincente. Tre partecipazioni STANDARD producono un jackpot congelato atteso di 1.000.240 centesimi; il gate verifica winner claim, payout unico, accredito delle altre play, nuovo round da 10.000,00 €, reveal, ricalcolo commitment e ricevute terminali coerenti. Una fault injection immediatamente prima di `WON → SETTLED` dimostra che anche un errore tardivo rollbacka scelta 20, winner, payout, crediti, ricevute, audit e round successivo, lasciando persistito soltanto lo stato pre-settlement.

Gate: `scripts/verify-m1.9.7.ps1/.sh`.


## Correzione M1.9.7.1 — Late Fault Audit Baseline Hotfix

La fault injection M1.9.7 ha confermato il rollback di tutti gli effetti del settlement, ma il test PHPUnit acquisiva il contatore audit prima dell'apertura dello step 20. `OpenPlayStep::open()` committa legittimamente `STEP_SHOWN` prima della scelta finale; la baseline corretta deve quindi essere acquisita dopo tale apertura. M1.9.7.1 corregge solo il test e la documentazione, senza modificare il settlement produttivo.

Gate: `scripts/verify-m1.9.7.1.ps1/.sh`.


## Stato implementazione M1.9.8 — Concurrency & Single-Winner Verification

Baseline di partenza: **M1.9.7.1 validata integralmente dall'utente**.

M1.9.8 aggiunge un gate multiprocesso reale: per tre round consecutivi due processi PHP distinti vengono sincronizzati su una barriera e inviano quasi simultaneamente la scelta 20 corretta di due play a 19/20. Ogni race deve produrre un solo `winner_play_id`, un solo `JACKPOT_PAYOUT`, un solo nuovo round `ACTIVE` e nessuna sovrascrittura del winner. Una terza challenge aperta prima della vittoria viene inviata dopo il settlement e deve risultare stale e priva di effetti.

`SubmitChoice` ritenta fino a tre volte l'intera transazione esclusivamente per `Doctrine\DBAL\Exception\RetryableException`, così una contesa SQLite/WAL viene risolta rileggendo lo stato autorevole dopo rollback invece di ritentare una singola query sul vecchio snapshot.

Il gate crea una snapshot consistente di `var/test.db` con `VACUUM INTO`, esegue le race reali e ripristina integralmente la snapshot al termine, rendendo il test ripetibile anche da PHPUnit.

Gate: `scripts/verify-m1.9.8.ps1/.sh`.
