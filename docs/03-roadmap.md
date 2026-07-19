# Roadmap

## M0.1 — Dominio e invarianti

- [x] Valori monetari virtuali in centesimi.
- [x] Percorso binario di venti bit.
- [x] Commitment con nonce di 256 bit.
- [x] Ciclo di vita minimo del round.
- [x] Invarianti documentati.
- [x] Test del nucleo eseguibili anche senza dipendenze esterne.

## M0.2 — Bootstrap Symfony e persistenza

- [x] Scheletro Symfony 7.4 LTS.
- [x] Configurazione Doctrine e SQLite.
- [x] Prima migrazione completa.
- [x] Pagina iniziale e health endpoint.
- [x] Script di bootstrap Windows e Unix.
- [x] Installazione dipendenze nell'ambiente dell'utente.
- [x] Correzione compatibilità PHPUnit 9.6.35.
- [x] Conferma finale della suite completa dopo M0.2.1.

## M1.1 — Catalogo delle coppie

- [x] CRUD amministrativo protetto da CSRF.
- [x] Categorie, ordinamento e stato attivo/disattivato.
- [x] 44 coppie regolari iniziali.
- [x] Porta finale obbligatoria e protetta.
- [x] Factory per 19 coppie più finale allo step 20.
- [x] Snapshot immutabile e hash deterministico.
- [x] Repository DBAL e migrazione SQLite.
- [x] Test di dominio e persistenza.
- [x] M1.1.1: compatibilità DoctrineBundle 3.x, KernelTestCase e bridge PHPUnit su Windows.
- [x] M1.1.1: bootstrap fail-fast e database di test deterministico.
- [x] M1.1.2: correzione compatibilità parser Windows PowerShell 5.1.
- [x] M1.1.3: compatibilità DoctrineBundle 3 / ORM 3 e validazione esplicita del kernel.
- [x] M1.1.6: connessione SQLite esplicita, indipendente da `DATABASE_URL`.

## M1.2 — Apertura del round

- [x] Selezione casuale di 19 coppie attive senza duplicati.
- [x] Generazione sicura della strada e del nonce.
- [x] Cifratura autenticata dei segreti a riposo.
- [x] Chiave locale generata fuori dal database e dallo ZIP.
- [x] Commitment pubblico.
- [x] Ledger append-only con seed virtuale di 10.000 €.
- [x] Apertura atomica e singolo round attivo.
- [x] Pagine amministrativa e pubblica del round.
- [x] Test di dominio, cifratura e persistenza.

## M1.3 — Giocata

- [x] Sessione anonima sicura con hash del token nel database.
- [x] Codice pubblico casuale e binding alla sessione.
- [x] Quota virtuale 100/80/20 registrata nel ledger.
- [x] Token monouso ruotato al refresh.
- [x] Timer server-side di due secondi.
- [x] Progressione da 1/20 a 20/20.
- [x] Protezioni refresh, replay, doppia scheda e doppio invio.
- [x] Audit di visualizzazioni, accettazioni e rifiuti.
- [x] Vincoli SQLite sulla progressione monotona.

## M1.4 — Vincita e reset atomico

- [x] Validazione crittografica del percorso alla ventesima scelta.
- [x] Primo vincitore rivendicato con transizione condizionale `ACTIVE → WON`.
- [x] Congelamento atomico del montepremio.
- [x] Un solo `JACKPOT_PAYOUT` virtuale per round.
- [x] Interruzione di tutte le giocate ancora aperte.
- [x] Credito di ripartenza univoco per ogni giocata interrotta.
- [x] Riscatto automatico del credito senza nuovo contributo 80/20.
- [x] Creazione e attivazione del round successivo da 10.000 € nella stessa transazione.
- [x] Settlement del vecchio round e audit concatenato degli eventi.
- [x] Trigger SQLite su transizioni, payout, crediti e campi vincitore.
- [x] Test di perdita, vincita/reset e riscatto credito.

## M1.5 — Verificabilità pubblica

- [x] Pubblicazione atomica del percorso vincente e del nonce al settlement.
- [x] Verifica pubblica e ricalcolo del commitment SHA-256.
- [x] Compatibilità con round M1.4 già `SETTLED` tramite pubblicazione lazy verificata.
- [x] Ricevuta immutabile `V-...` per giocate vinte, perse e interrotte.
- [x] Hash canonico della ricevuta e controllo di integrità.
- [x] Verifica incrociata ricevuta/esito/percorso/giocata vincente.
- [x] Storico pubblico dei round con stato della verifica.
- [x] Trigger SQLite su pubblicazione e immutabilità delle ricevute.
- [x] Documentazione commit-reveal e formato delle prove pubbliche.

## M1.6 — Simulazione, statistiche e amministrazione

- [x] Motore di simulazione isolato da round, ledger e giocate reali.
- [x] PRNG deterministico con seed per riproducibilità.
- [x] Profili `UNIFORM`, `FIXED_A_BIAS` e `ALTERNATING_BIAS`.
- [x] Distribuzioni configurabili delle preferenze A/B.
- [x] Bitset da 128 KiB per copertura esatta dei 1.048.576 percorsi.
- [x] Percorsi distinti, duplicati, frequenze e top 50.
- [x] Entropia empirica e numero effettivo di percorsi osservati.
- [x] Distribuzione A/B osservata per ciascuno dei 20 step.
- [x] Metriche reali aggregate su giocate, round e montepremio virtuale.
- [x] Dashboard amministrativa e storico dei run.
- [x] Esportazione CSV delle statistiche aggregate.
- [x] Comando CLI per simulazioni massive fino a 1.000.000 di giocate.
- [x] Persistenza immutabile separata in tabelle `simulation_*`.

## M1.7 — Sicurezza, robustezza e osservabilità

- [x] Rate limiting applicativo per avvio giocata, caricamento step, invio scelta e verifica.
- [x] Request ID server-side sugli endpoint HTTP.
- [x] Security headers HTTP e Content Security Policy.
- [x] JavaScript gameplay esterno compatibile con `script-src 'self'`.
- [x] Logging JSONL strutturato con redazione esplicita di token e segreti.
- [x] Accesso `/admin/*` locale per default e allowlist IP/CIDR configurabile.
- [x] Liveness `/health` e readiness `/ready` separati.
- [x] Diagnostica amministrativa e comando `app:system:check`.
- [x] Verifica completa della catena hash audit.
- [x] Test di fault injection sul settlement atomico.
- [x] Test di richiesta stale concorrente dopo la vittoria.
- [x] Hardening SQLite: foreign key, busy timeout, WAL, FULL sync e autocheckpoint.
- [x] Threat model e runbook operativo.

## M1.8 — Accesso amministrativo, E2E e rifinitura UI

- [x] Autenticazione amministrativa reale con password hashata e sessione dedicata.
- [x] Ruoli/authorization espliciti per tutte le operazioni `/admin`.
- [x] Invalidazione sessioni tramite `auth_version` dopo revoca/password/ruolo.
- [x] Protezione DB dell'ultimo `SUPER_ADMIN` attivo.
- [x] Eliminazione di `style-src 'unsafe-inline'` dalla CSP.
- [x] CSS esterno, focus visibile, skip link e reduced-motion.
- [x] Rifinitura responsive e accessibilità tastiera/screen reader.
- [x] Test end-to-end browser del percorso completo 1/20 → risultato.
- [x] Test E2E di vincita, reset globale e credito di ripartenza.
- [x] Test E2E login/logout e matrice ruoli.
- [x] Pagine errore 403/404/429/500 coerenti e non informative.
- [x] Checklist release e configurazione demo remota sicura.

## M1.9 — Verification & Hardening

Lo sviluppo di nuove funzionalità è temporaneamente sospeso per verificare l'intero processo pezzo per pezzo. Ogni milestone richiede checklist manuale, test automatici, documentazione aggiornata, ZIP completo e validazione esplicita prima di procedere. Il piano operativo dettagliato è in `docs/15-verification-hardening-plan.md`.

### M1.9.1 — Environment & Database Verification

- [x] Preflight unico Windows/Unix per PHP, estensioni, PDO SQLite, crypto e filesystem runtime.
- [x] Audit automatico del pacchetto per escludere segreti, database, vendor e artefatti runtime.
- [x] Verifica post-migrazione di path DB, separazione dev/test, migrazioni, seed e PRAGMA.
- [x] Reset deterministico `test.db`/WAL/SHM/journal estratto e coperto da test.
- [x] Script di verifica che eseguono due bootstrap consecutivi e CI con migrazioni ripetute.
- [x] **Gate:** validazione su estrazione pulita con suite completa verde e bootstrap ripetibile.

### M1.9.2 — Catalog & Round Creation Verification

- [x] CRUD catalogo e invarianti della porta finale.
- [x] Minimo 19 coppie regolari attive.
- [x] Snapshot immutabili e selezione 19 + porta finale.
- [x] Apertura atomica di un solo round `ACTIVE`.
- [x] **Gate:** catalogo e round coerenti senza effetti retroattivi; M1.9.2.1.3 validata con suite completa e gate transazionale verdi.

### M1.9.2.1 — Runtime Compatibility & Monotonic Timing Hardening

- [x] PHP 8.4 impostato come baseline coerente in manifest, lock, bootstrap, preflight, README e CI.
- [x] `config.platform.php = 8.4.0` blocca la risoluzione Composer sulla baseline minima supportata.
- [x] `composer check-platform-reqs` aggiunto a bootstrap e CI.
- [x] Timer browser convertito da epoch assoluti/`Date.now()` a durate relative/`performance.now()`.
- [x] Policy e test anti-regressione aggiunti.
- [x] **Gate:** validazione completa PHP 8.4+ insieme al gate M1.9.2 tramite la hotfix M1.9.2.1.3.

### M1.9.3 — Cryptographic Commitment Verification

- [x] Ricalcolo end-to-end del commitment su un round reale transazionale.
- [x] Tamper test indipendenti su percorso, nonce, round e question set.
- [x] Cifratura autenticata contestuale e materiale persistito immutabile.
- [x] Nessun reveal durante `ACTIVE` in DB, log, endpoint, payload HTTP e DOM.
- [x] **Gate:** validazione esplicita con `verify-m1.9.3.ps1/.sh`; ogni manomissione deve essere rilevabile.

### M1.9.4 — Play Start & Accounting Verification

- [x] Sessione anonima pre-emessa sulla Home, cookie sicuro e token persistito solo come hash.
- [x] Una sola giocata aperta per sessione/round.
- [x] Contabilizzazione virtuale 1,00 = 0,80 + 0,20 con vincoli DB per play/correlation.
- [x] Doppio avvio idempotente sulla stessa sessione; POST privo di identità pre-esistente non crea una quota.
- [x] **Gate:** validazione esplicita con `verify-m1.9.4.1.ps1/.sh`; una partecipazione genera una sola giocata e una sola quota, con schema SQLite verificato prima dello scenario.

#### M1.9.4.1 — Accounting Schema Enforcement Hotfix

- [x] Nuova migration che ricrea i tre indici unici STANDARD e il trigger di binding/duplicazione.
- [x] Trigger rafforzato: seconda componente dello stesso tipo sulla stessa play sempre respinta.
- [x] Preflight del gate su `sqlite_master`.
- [x] Migrazioni test sincronizzate immediatamente prima del gate.
- [x] **Gate:** suite completa + `Duplicate accounting protection` verde per entry, jackpot e organizer.

### M1.9.5 — Step, Timer & Anti-Replay Verification

- [x] Timer server-side 1,999 s rifiutato / 2,000 s accettato a livello dominio, applicazione e SQLite.
- [x] Refresh, token rotation, replay idempotente e doppia scheda.
- [x] Tentativi di salto step e manipolazione HTTP con campi non autorevoli.
- [x] Idempotency key `request_id` scoped per giocata.
- [x] **Gate:** validazione esplicita con `verify-m1.9.5.ps1/.sh`; il client non controlla la macchina a stati.

### M1.9.6 — Full Losing Journey Verification

- [x] Percorso completo 1/20 → 20/20 perdente implementato nel gate transazionale e nell'E2E browser.
- [x] `COMPLETED_LOST`, round ancora `ACTIVE`, 20 step persistiti e ricevuta valida.
- [x] Nessun reveal anticipato; nessun winner, payout, credito o nuovo round causato dalla perdita.
- [x] Nuova partecipazione della stessa sessione consentita nello stesso round.
- [ ] **Gate:** validazione esplicita con `verify-m1.9.6.ps1/.sh`; una perdita chiude solo la giocata.

### M1.9.7 — Winning Settlement Verification

- [ ] Percorso vincente completo.
- [ ] Winner claim, freeze jackpot, payout, crediti, nuovo round, reveal e settlement atomici.
- [ ] Riconciliazione del jackpot congelato.
- [ ] **Gate:** nessuno stato intermedio osservabile.

### M1.9.8 — Concurrency & Single-Winner Verification

- [ ] Due richieste vincenti concorrenti.
- [ ] Richieste stale dopo la vittoria.
- [ ] Un solo vincitore, payout e nuovo round.
- [ ] **Gate:** unicità del vincitore garantita dal database.

### M1.9.9 — Reset & Restart Credit Verification

- [ ] Reset di giocate a step differenti.
- [ ] Un credito monouso per ogni giocata interrotta.
- [ ] Riscatto senza nuovo 0,80/0,20.
- [ ] Token e URL del vecchio round inutilizzabili.
- [ ] **Gate:** credito neutro, univoco e non riutilizzabile.

### M1.9.10 — Receipt & Public Verification Audit

- [ ] Ricevute WON/LOST/INTERRUPTED.
- [ ] Integrità e immutabilità delle ricevute.
- [ ] Verifica pubblica completa dopo settlement.
- [ ] **Gate:** risultato verificabile senza fidarsi dell'amministratore.

### M1.9.11 — Ledger & Audit Integrity Verification

- [ ] Riconciliazione ledger al centesimo.
- [ ] Append-only e tentativi UPDATE/DELETE respinti.
- [ ] Verifica catena hash audit e tamper detection.
- [ ] **Gate:** contabilità riconciliabile e storia alterata rilevabile.

### M1.9.12 — Admin, Authorization & HTTP Security Verification

- [ ] Matrice `SUPER_ADMIN` / `OPERATOR` / `AUDITOR`.
- [ ] Login, logout, rate limit, revoca sessioni e ultimo super admin.
- [ ] Allowlist IP, CSP, security header, cookie e pagine errore.
- [ ] **Gate:** nessuna escalation di privilegi o esposizione di segreti.

### M1.9.13 — Fault Injection & Recovery

- [ ] Errori artificiali nei punti critici del settlement.
- [ ] Rollback totale e assenza di mezzi settlement.
- [ ] Restart/crash recovery, WAL, lock e quick check.
- [ ] **Gate:** nessuna corruzione persistente dopo errore o restart.

### M1.9.14 — Performance, SQLite Limits & Simulation Isolation

- [ ] Carico progressivo 10 / 100 / 1.000 / 10.000 workload equivalenti.
- [ ] Latenze, lock, WAL, crescita DB/audit e busy timeout.
- [ ] Snapshot prima/dopo simulazioni per provare isolamento.
- [ ] Criteri documentati per futura migrazione a PostgreSQL.
- [ ] **Gate:** limiti operativi misurati, non presunti.

### M1.9.15 — Final Full-Journey Acceptance

- [ ] Installazione pulita → admin → R1 → perdita → rigiocata → vittoria.
- [ ] Reset concorrenti → crediti → R2 → riscatto neutro.
- [ ] Ricevute, commitment, storico, ledger, audit e diagnostica.
- [ ] Restart finale e nuova verifica di consistenza.
- [ ] **Gate finale:** solo dopo validazione completa si riapre M2.1.

## M2.1 — Esperienza di gioco e contenuti

- [ ] Curatela delle coppie per varietà, ambiguità e interesse visivo.
- [ ] Gruppi/temi e regole per evitare domande semanticamente troppo simili nello stesso round.
- [ ] Miglioramento visuale della scelta, transizioni accessibili e feedback senza rivelare informazioni sul percorso.
- [ ] Modalità demo guidata e onboarding del primo utilizzo.
- [ ] Statistiche editoriali sulle coppie più sbilanciate, senza profilazione individuale.
- [ ] Test di usabilità mobile e desktop.
