# M1.1 — Catalogo delle coppie

## Obiettivo

Il catalogo contiene le coppie binarie utilizzabili nei primi diciannove passaggi. La ventesima scelta è sempre la porta finale di sistema.

## Tipi di coppia

- `REGULAR`: selezionabile per gli step da 1 a 19.
- `FINAL_DOOR`: coppia di sistema obbligatoria per lo step 20.

La migrazione iniziale M1.1 inserisce 44 coppie regolari attive e una porta finale:

```text
Porta rossa / Porta blu
```

## Regole del catalogo

- Il codice è univoco, in minuscolo e usa soltanto lettere, numeri e trattini.
- Le due opzioni devono essere non vuote e differenti.
- Ogni coppia appartiene a una categoria editoriale.
- Le coppie regolari possono essere create, modificate, attivate, disattivate ed eliminate.
- La porta finale può essere rinominata, ma non può cambiare codice, essere disattivata o eliminata.
- Devono essere disponibili almeno 19 coppie regolari attive prima dell'apertura di un round.

La protezione della porta finale è applicata sia nel dominio PHP sia tramite trigger/indice SQLite. M1.9.2 verifica esplicitamente anche il percorso applicativo di eliminazione, così la porta finale viene rifiutata come regola di dominio prima di arrivare al database.

## Snapshot del round

Quando verrà preparato un round, il sistema selezionerà esattamente 19 coppie regolari attive e aggiungerà la porta finale allo step 20.

Per ogni domanda vengono copiati:

- identificativo immutabile della coppia sorgente (`choice_pair_source_id_snapshot`);
- riferimento opzionale alla riga corrente del catalogo (`choice_pair_id`);
- codice;
- testo dell'opzione A;
- testo dell'opzione B;
- categoria;
- tipo della coppia;
- numero dello step.

La copia è immutabile. Modificare o eliminare una coppia dal catalogo non cambia i round già preparati.

Da M1.9.2 l'identità sorgente usata nell'hash è conservata in una colonna snapshot dedicata. `choice_pair_id` resta soltanto un riferimento vivo al catalogo: quando una coppia regolare viene eliminata può diventare `NULL` tramite la foreign key, mentre identità, testi, codice, categoria, tipo, step e `question_set_hash` restano invariati. Il trigger ammette esclusivamente questa nullificazione referenziale e continua a rifiutare qualunque modifica del contenuto storico.

## Hash del set di domande

Lo snapshot completo viene serializzato in forma canonica e sottoposto a SHA-256. L'hash risultante entra nel commitment del round, impedendo di sostituire le domande senza invalidare la verifica finale.

## Interfaccia amministrativa

Il catalogo è disponibile in:

```text
/admin/scelte
```

Sono presenti:

- elenco ordinato;
- numero di coppie regolari attive;
- indicatore di prontezza rispetto al minimo di 19;
- creazione e modifica;
- attivazione e disattivazione;
- eliminazione delle sole coppie non di sistema;
- protezione CSRF per tutte le operazioni di scrittura.
