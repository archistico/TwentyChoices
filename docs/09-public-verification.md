# M1.5 — Verificabilità pubblica, ricevute e storico

## Obiettivo

M1.5 rende verificabile dall'esterno ciò che nelle milestone precedenti era garantito soltanto dal server e dal database.

La verificabilità è divisa in due prove indipendenti:

1. **prova del round**: dimostra che il percorso vincente non è stato cambiato dopo l'apertura;
2. **prova della giocata**: dimostra che una specifica partecipazione ha un determinato percorso, esito e numero progressivo.

Nessun segreto viene pubblicato mentre il round è `ACTIVE` o `WON`. Percorso e nonce diventano pubblici soltanto nel passaggio atomico a `SETTLED`.

## Commitment del round

All'apertura viene pubblicato:

```text
twenty-choices-v1:<round-code>:<question-set-hash>:<20-bits>:<nonce-hex>
```

Il server pubblica soltanto l'hash SHA-256 del payload. Il percorso di 20 bit e il nonce casuale da 32 byte restano cifrati.

Al settlement vengono pubblicati in modo immutabile:

- `revealed_winning_path`;
- `revealed_secret_nonce_hex`;
- `verification_published_at`.

Chiunque può ricostruire lo stesso payload canonico e verificare che il suo SHA-256 coincida con `secret_commitment`.

## Pubblicazione atomica

Per i nuovi round M1.5 la stessa transazione vincente esegue:

1. validazione del percorso vincente;
2. `ACTIVE → WON`;
3. congelamento del jackpot;
4. payout virtuale e crediti;
5. apertura del round successivo;
6. pubblicazione di percorso e nonce;
7. `WON → SETTLED`;
8. commit.

Un trigger SQLite impedisce `WON → SETTLED` se il materiale di verifica non è presente.

Dopo la prima pubblicazione, percorso, nonce e timestamp non possono più essere modificati.

## Compatibilità con round M1.4 già conclusi

Un database aggiornato può contenere round `SETTLED` creati prima dell'introduzione delle colonne pubbliche.

Alla prima consultazione di uno di questi round:

1. il server decifra localmente percorso e nonce;
2. ricostruisce e verifica il commitment preesistente;
3. se la verifica è valida, pubblica i valori una sola volta;
4. registra `ROUND_VERIFICATION_PUBLISHED` nell'audit;
5. i trigger rendono i valori immutabili.

Se il commitment non coincide, la pubblicazione viene rifiutata.

## Ricevuta della giocata

Ogni giocata terminale riceve un codice casuale indipendente:

```text
V-<24 caratteri esadecimali casuali>
```

La tabella `play_receipt` fotografa:

- codice di verifica;
- codice giocata;
- codice round;
- numero progressivo della partecipazione;
- tipo di ingresso (`STANDARD` o `RESTART_CREDIT`);
- esito (`WON`, `LOST`, `INTERRUPTED`);
- numero di scelte registrate;
- percorso registrato fino al momento terminale;
- timestamp di emissione;
- hash SHA-256 della ricevuta.

La ricevuta viene creata nella stessa transazione che rende terminale la giocata.

## Hash della ricevuta

Il formato canonico è:

```text
twenty-choices-receipt-v1
|<verification-code>
|<play-code>
|<round-code>
|<participation-number>
|<entry-kind>
|<outcome>
|<completed-steps>
|<chosen-path-bits>
|<issued-at>
```

I campi vengono concatenati con `|` su una singola stringa e sottoposti a SHA-256.

L'hash non sostituisce le firme digitali esterne; nel prototipo serve a rendere rilevabile qualsiasi differenza tra la ricevuta pubblicata e lo snapshot originale. La tabella è inoltre protetta da trigger append-only.

## Momento della verificabilità

### Giocata perdente mentre il round è ancora attivo

La ricevuta può essere verificata immediatamente per integrità, ma il percorso vincente non è ancora pubblico.

Lo stato pubblico è quindi:

```text
Ricevuta: valida
Esito registrato: LOST
Verifica commitment del round: in attesa del settlement
```

### Giocata vincente

Dopo il commit della transazione vincente sono verificabili insieme:

- integrità della ricevuta;
- percorso scelto dal vincitore;
- identità pubblica della giocata vincente;
- percorso vincente pubblicato;
- nonce;
- commitment originario;
- jackpot congelato.

### Giocata interrotta

La ricevuta conserva il percorso parziale e il numero di scelte realmente completate. Non afferma che quel percorso fosse vincente o perdente: l'esito è `INTERRUPTED`.

## Endpoint pubblici

- `/storico` — ultimi round e stato della verifica;
- `/round/{codice}` — commitment e, dopo settlement, materiale pubblico di verifica;
- `/verifica/{codice}` — ricevuta della singola partecipazione e verifica incrociata con il round.

## Invarianti SQLite M1.5

I trigger impediscono:

- settlement di un nuovo round senza materiale pubblico di verifica;
- pubblicazione di un percorso diverso da 20 bit;
- pubblicazione di un nonce diverso da 32 byte esadecimali;
- modifica del materiale dopo la pubblicazione;
- ricevute riferite a una giocata non terminale o con snapshot incoerente;
- modifica o eliminazione di una ricevuta;
- più ricevute per la stessa giocata;
- duplicazione di codice pubblico o hash ricevuta.

## Limiti intenzionali

M1.5 non introduce ancora:

- firma digitale con chiave pubblica esterna;
- timestamp authority indipendente;
- anchoring su blockchain o servizi terzi;
- esportazione firmata del registro audit;
- certificazione di un RNG da parte di un ente esterno.

Questi elementi non sono necessari per il simulatore tecnico, ma il modello dati consente future estensioni senza modificare il principio commit-reveal.
