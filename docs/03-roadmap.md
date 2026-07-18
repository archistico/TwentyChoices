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

- Generazione sicura della strada e del nonce.
- Cifratura dei segreti a riposo.
- Commitment pubblico.
- Ledger con seed virtuale di 10.000 €.

## M1.3 — Giocata

- Sessione anonima sicura.
- Token monouso.
- Timer server-side di due secondi.
- Progressione da 1/20 a 20/20.
- Protezioni refresh, replay e doppia scheda.

## M1.4 — Vincita e reset atomico

- Primo vincitore validato.
- Congelamento del montepremio.
- Interruzione delle giocate aperte.
- Crediti di ripartenza.
- Creazione del round successivo nella stessa unità di lavoro.
