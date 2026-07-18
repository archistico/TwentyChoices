# M1.3 — Giocata e progressione temporizzata

## Obiettivo

La milestone M1.3 introduce la progressione temporizzata; dalla M1.4 la ventesima scelta viene validata immediatamente nella stessa transazione e dalla M1.5 ogni esito terminale riceve anche una ricevuta pubblica verificabile.

## Sessione anonima

Al primo avvio di una giocata il server genera un token casuale da 256 bit. Il browser lo conserva in un cookie:

- `HttpOnly`;
- `SameSite=Lax`;
- `Secure` quando la richiesta usa HTTPS;
- durata massima di un anno.

Nel database viene memorizzato soltanto l'hash SHA-256. Il codice pubblico della giocata è casuale e non progressivo, ma non costituisce da solo un'autorizzazione: deve corrispondere anche alla sessione anonima.

## Avvio della giocata

L'avvio è atomico e registra:

- giocata in stato `IN_PROGRESS`;
- numero progressivo della partecipazione nel round;
- movimento virtuale `PLAYER_ENTRY` da 100 centesimi;
- contributo `JACKPOT_CONTRIBUTION` da 80 centesimi;
- quota `ORGANIZER_SHARE` da 20 centesimi;
- incremento del montepremio di 80 centesimi;
- evento di audit `PLAY_STARTED`.

Una sessione può avere una sola giocata aperta nello stesso round. Un secondo comando di avvio riprende la giocata esistente senza contabilizzare una nuova quota.

## Apertura dello step

Lo step mostrato è sempre `current_step + 1`. Alla prima visualizzazione il server salva:

- domanda congelata del round;
- posizione casuale delle opzioni A e B;
- hash del token monouso;
- `shown_at`;
- `available_at = shown_at + 2 secondi`.

Un refresh non modifica `shown_at`, `available_at` o la posizione delle opzioni. Viene invece ruotato il token monouso: l'ultima pagina caricata è l'unica che può inviare la risposta.

## Invio della scelta

Il client invia soltanto:

- token monouso;
- opzione canonica `A` o `B`;
- UUID della richiesta;
- tempo client a fini diagnostici.

Il server verifica autonomamente sessione, giocata, round, step, token e orario. L'orologio del browser non ha valore autorizzativo.

Una risposta accettata aggiorna nello stesso commit:

1. `play_step`, con scelta e timestamp;
2. `play`, con avanzamento di un solo step e aggiunta di un solo bit;
3. audit, con tempi server e client.

L'UUID rende idempotente il doppio invio della stessa richiesta. Una richiesta con un vecchio token viene rifiutata.

## Completamento 20/20

Alla ventesima scelta, nella stessa transazione, vengono salvati `current_step = 20`, percorso completo e `completed_at`, quindi viene invocata immediatamente la risoluzione:

- percorso diverso dalla strada segreta → `COMPLETED_LOST`;
- percorso corretto e prima rivendicazione valida → `COMPLETED_WON` e settlement globale;
- eventuali altre giocate ancora aperte → `INTERRUPTED` e poi `CREDITED`.

Non esiste più uno stato applicativo intermedio "in attesa di validazione" per le nuove giocate. La compatibilità con eventuali record M1.3 legacy a 20/20 viene gestita alla prima riapertura.

Dalla M1.5, ogni giocata terminale riceve una sola ricevuta `V-...` immutabile. La ricevuta è subito verificabile per integrità; il confronto crittografico completo con la strada vincente diventa possibile quando il round è `SETTLED`.

## Audit

Sono registrati almeno:

- `PLAYER_SESSION_CREATED`;
- `PLAY_STARTED`;
- `STEP_SHOWN`;
- `STEP_TOKEN_ROTATED`;
- `CHOICE_ACCEPTED`;
- `CHOICE_REJECTED_TOO_EARLY`;
- `CHOICE_REJECTED_INVALID_TOKEN`;
- `CHOICE_REPLAY_IDEMPOTENT`;
- `PLAY_COMPLETED_LOST`;
- `ROUND_WON`;
- `PLAY_INTERRUPTED_BY_WINNER`;
- `ROUND_VERIFICATION_PUBLISHED`;
- `ROUND_SETTLED`.

Gli eventi formano una catena append-only tramite `previous_hash` ed `event_hash`.
