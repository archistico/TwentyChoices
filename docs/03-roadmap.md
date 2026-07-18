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

## M2.1 — Esperienza di gioco e contenuti

- [ ] Curatela delle coppie per varietà, ambiguità e interesse visivo.
- [ ] Gruppi/temi e regole per evitare domande semanticamente troppo simili nello stesso round.
- [ ] Miglioramento visuale della scelta, transizioni accessibili e feedback senza rivelare informazioni sul percorso.
- [ ] Modalità demo guidata e onboarding del primo utilizzo.
- [ ] Statistiche editoriali sulle coppie più sbilanciate, senza profilazione individuale.
- [ ] Test di usabilità mobile e desktop.
