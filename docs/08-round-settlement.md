# M1.4 — Vincita e reset atomico

## Obiettivo

La ventesima scelta non viene validata in un processo successivo: registrazione della risposta, confronto con il percorso segreto ed eventuale chiusura del round appartengono alla stessa transazione database.

## Percorso perdente

Se i 20 bit scelti non coincidono con il percorso segreto:

1. la giocata passa da `IN_PROGRESS` a `COMPLETED_LOST`;
2. il round rimane `ACTIVE`;
3. il montepremio non viene congelato;
4. non viene creato alcun payout;
5. il giocatore può iniziare una nuova partecipazione.

Il sistema non rivela quale scelta fosse differente.

## Percorso vincente

Prima di confrontare il percorso, il server:

- decifra percorso e nonce con il contesto crittografico specifico del round;
- ricostruisce il `RoundCommitment`;
- verifica che commitment, codice round, hash domande, percorso e nonce coincidano.

La rivendicazione della vittoria avviene con un aggiornamento condizionale equivalente a:

```sql
UPDATE game_round
SET status = 'WON', ...
WHERE id = :roundId
  AND status = 'ACTIVE'
  AND winner_play_id IS NULL;
```

Solo la transazione che aggiorna una riga diventa vincitrice.

## Ordine atomico di settlement

Nella transazione della ventesima scelta vincente:

1. `ACTIVE → WON` sul round corrente;
2. congelamento di `initial_jackpot_cents + entry_contribution_cents`;
3. `IN_PROGRESS → COMPLETED_WON` sulla giocata vincente;
4. inserimento univoco di `JACKPOT_PAYOUT`;
5. interruzione delle altre giocate `CREATED` / `IN_PROGRESS`;
6. emissione di un solo `play_credit` per ogni giocata interrotta;
7. registrazione `RESTART_CREDIT_ISSUED`;
8. creazione completa del nuovo round;
9. nuovo `BANK_SEED` da 1.000.000 centesimi virtuali;
10. attivazione del nuovo round;
11. `WON → SETTLED` sul vecchio round;
12. commit.

Un errore in qualunque punto provoca rollback dell'intero blocco.

## Credito di ripartenza

Una giocata interrotta viene marcata `CREDITED` dopo l'emissione del credito.

Il credito:

- appartiene alla stessa sessione anonima della giocata interrotta;
- ha una sola sorgente (`source_play_id` univoco);
- nasce in stato `AVAILABLE`;
- può passare una sola volta a `REDEEMED`;
- crea una nuova giocata con `entry_kind = RESTART_CREDIT`;
- non incrementa `entry_contribution_cents`;
- non crea `PLAYER_ENTRY`, `JACKPOT_CONTRIBUTION` o `ORGANIZER_SHARE`;
- registra soltanto `RESTART_CREDIT_REDEEMED` come movimento di tracciamento virtuale.

## Invarianti SQLite M1.4

I trigger impediscono, tra l'altro:

- round creati direttamente in stato diverso da `PREPARING`;
- modifica anticipata di vincitore, jackpot congelato o `won_at`;
- transizioni di stato round non previste;
- `WON` senza giocata completa da 20 scelte;
- `SETTLED` senza un payout univoco;
- `COMPLETED_WON` senza che il round abbia già rivendicato quella giocata;
- emissione di crediti per giocate non interrotte;
- modifica della sorgente di un credito;
- doppio riscatto del credito;
- payout con importo diverso dal jackpot congelato;
- movimenti credito con importo diverso da 1,00 € virtuale.

## Estensione M1.5

M1.5 completa il settlement pubblicando nella stessa transazione percorso vincente, nonce e timestamp di pubblicazione. La transizione `WON → SETTLED` viene rifiutata se questi dati mancano. Le ricevute delle giocate terminali vengono create nella stessa unità di lavoro e sono immutabili. Vedi `docs/09-public-verification.md`.
