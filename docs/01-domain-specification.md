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
- Percorso e nonce sono persistiti esclusivamente in forma cifrata e autenticata.
- Il commitment viene pubblicato prima dell’attivazione e resta immutabile.
- Un round può avere al massimo un vincitore.
- Un round può passare a `WON` soltanto da `ACTIVE`.
- Il payout congelato non può essere modificato dopo la vincita.
- Strada e nonce possono essere pubblicati soltanto quando il round diventa `SETTLED`.
- Un nuovo round non può diventare `SETTLED` senza pubblicare atomicamente percorso, nonce e timestamp di pubblicazione.
- Il materiale pubblico di verifica è immutabile dopo la prima pubblicazione.
- Il materiale pubblicato deve ricostruire esattamente il commitment originario.

## Invarianti della giocata

- Una giocata appartiene a un solo round e a una sola sessione.
- Una sessione può avere al massimo una giocata aperta nello stesso round.
- Lo step corrente è compreso tra 0 e 20.
- La lunghezza del percorso registrato coincide con lo step corrente.
- Ogni coppia `(play_id, step_number)` è unica.
- Un challenge token può essere utilizzato una sola volta.
- Una scelta accettata è soltanto `A` o `B`.
- Una giocata interrotta genera al massimo un credito.
- Ogni giocata terminale può avere una sola ricevuta pubblica di verifica.
- La ricevuta fotografa codice giocata, round, numero partecipazione, esito, step e percorso registrato.
- Una ricevuta emessa non può essere aggiornata né eliminata.

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


## Invarianti della simulazione statistica

- Una simulazione non è una giocata e non partecipa a un round reale.
- Una simulazione non può modificare `game_round`, `play`, `play_step`, `ledger_entry`, `play_credit`, `play_receipt` o `audit_event`.
- Una simulazione non legge né decifra il percorso vincente di un round attivo.
- Il seed rende il campione riproducibile a parità di algoritmo, profilo e parametri.
- I profili con bias sono sintetici e non rappresentano probabilità umane misurate.
- Le statistiche persistite sono aggregati immutabili.
- La copertura è sempre riferita allo spazio completo di 1.048.576 percorsi.
- L'esportazione CSV non contiene identificativi di sessione o dati personali.

## Invarianti di sicurezza operativa

- Il client non può rendere autorevoli IP, step, timer, token, stato round o correlation/request ID.
- Le chiavi del rate limiter sono HMAC e non persistono IP o cookie in chiaro.
- Un endpoint limitato restituisce `429` con `Retry-After` senza eseguire il caso d’uso applicativo.
- L’area amministrativa è default-deny fuori dagli IP esplicitamente consentiti.
- I security log non devono contenere token, nonce, cookie, authorization header o percorsi segreti.
- Ogni richiesta HTTP principale riceve un request ID generato dal server.
- La catena audit deve essere contigua e ogni `event_hash` deve essere ricalcolabile dai campi persistiti.
- Un errore durante il settlement vincente deve annullare anche la ventesima scelta e ogni side effect successivo.
- Una richiesta stale successiva a una vittoria non può cambiare vincitore, payout o stato del round.
- `/health` non dipende dal database; `/ready` deve fallire se lo schema applicativo non è interrogabile.

## M1.8 — Dominio amministrativo

L'amministrazione introduce un'identità separata dal `player_session` anonimo. Un `admin_user` non partecipa alle giocate e non può essere usato come identità del giocatore.

Invarianti:

- username univoco case-insensitive;
- password mai persistita in chiaro;
- ruolo in `SUPER_ADMIN | OPERATOR | AUDITOR`;
- un account disattivato non può mantenere una sessione valida;
- ogni cambio password, ruolo o stato incrementa `auth_version`;
- la sessione è valida solo se la sua `auth_version` coincide con quella persistita;
- deve esistere almeno un `SUPER_ADMIN` attivo prima di poter disattivare/declassare un altro `SUPER_ADMIN` attivo;
- autorizzazione deny-by-default per le route amministrative.

L'allowlist IP è un pre-requisito di rete e non sostituisce l'identità amministrativa.
