# M0.1 — Specifica del dominio

## Scopo

TwentyChoices è un simulatore tecnico gratuito. Tutti gli importi, le quote, i crediti e i premi sono virtuali e non hanno valore monetario.

## Regole consolidate

1. Ogni round contiene esattamente venti scelte binarie.
2. Esistono `2^20 = 1.048.576` percorsi completi.
3. Ogni round possiede una sola strada segreta globale, condivisa da tutte le giocate.
4. La strada è immutabile dall'attivazione alla chiusura del round.
5. Il montepremio iniziale virtuale è sempre `1.000.000` centesimi, ossia `10.000,00 €`.
6. Una partecipazione standard vale virtualmente `100` centesimi: `80` al montepremio e `20` all'organizzazione.
7. Una partecipazione ottenuta con credito di ripartenza non genera un secondo contributo al montepremio né una seconda quota organizzativa.
8. Il primo percorso corretto validato atomicamente dal database chiude il round.
9. Tutte le altre giocate aperte vengono interrotte e producono esattamente un credito di ripartenza ciascuna.
10. Il round successivo parte da una nuova strada segreta e da un nuovo montepremio virtuale di `10.000,00 €`.
11. Prima di ogni scelta devono trascorrere almeno due secondi misurati dall'orologio del server.
12. Il client non è mai autorevole per step corrente, timer, percorso, stato del round o montepremio.

## Invarianti del round

- Un solo round può essere `ACTIVE`.
- Un round `ACTIVE` non può cambiare strada, nonce, set di domande o commitment.
- Un round può avere al massimo un vincitore.
- Un round può passare a `WON` soltanto da `ACTIVE`.
- Il payout congelato non può essere modificato dopo la vincita.
- Strada e nonce possono essere pubblicati soltanto dopo la vincita.

## Invarianti della giocata

- Una giocata appartiene a un solo round e a una sola sessione.
- Una sessione può avere al massimo una giocata aperta nello stesso round.
- Lo step corrente è compreso tra 0 e 20.
- La lunghezza del percorso registrato coincide con lo step corrente.
- Ogni coppia `(play_id, step_number)` è unica.
- Un challenge token può essere utilizzato una sola volta.
- Una scelta accettata è soltanto `A` o `B`.
- Una giocata interrotta genera al massimo un credito.

## Invarianti del catalogo

- Un set di round contiene esattamente 19 coppie regolari attive e la porta finale allo step 20.
- La stessa coppia non può apparire due volte nello stesso round.
- La porta finale è una coppia di sistema sempre attiva, non disattivabile e non eliminabile.
- Lo snapshot conserva i dati visualizzati al momento della preparazione del round.
- Uno snapshot già associato a un round non può essere aggiornato o sostituito.
- L’hash del set di domande è deterministico e partecipa al commitment del round.

## Invarianti contabili

- Gli importi sono sempre interi espressi in centesimi.
- Il ledger è append-only.
- Una stessa correlazione non può produrre due movimenti dello stesso tipo.
- Le correzioni sono movimenti compensativi; i movimenti esistenti non vengono aggiornati o cancellati.
