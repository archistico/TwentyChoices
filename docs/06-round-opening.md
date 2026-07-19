# M1.2 — Apertura verificabile del round

## Obiettivo

Creare un round globale completo senza rendere disponibile al client o agli amministratori applicativi il percorso vincente in chiaro.

## Sequenza dell'operazione

`OpenRound` esegue questi passaggi:

1. carica tutte le coppie regolari attive;
2. ne seleziona 19 senza duplicati con Fisher–Yates e `random_int()`;
3. aggiunge la porta finale allo step 20;
4. crea lo snapshot e il relativo hash SHA-256;
5. genera il percorso vincente con `random_int(0, 1_048_575)`;
6. genera un nonce di commitment con `random_bytes(32)`;
7. calcola il commitment pubblico;
8. cifra percorso e nonce separatamente;
9. apre una transazione DBAL;
10. inserisce il round in stato `PREPARING`;
11. salva i 20 snapshot;
12. registra `BANK_SEED = 1.000.000` centesimi virtuali;
13. attiva il round;
14. conferma la transazione.

## Cifratura dei segreti

`AuthenticatedRoundSecretCipher` usa in ordine di preferenza:

1. Sodium `secretbox` — XSalsa20-Poly1305;
2. OpenSSL `AES-256-GCM` come fallback.

La chiave effettiva viene derivata da `APP_SECRET` tramite HKDF-SHA-256. Per ogni segreto viene derivata una sottochiave legata al contesto:

```text
round:<ULID>:winning-path
round:<ULID>:commitment-nonce
```

Questa separazione impedisce di copiare un ciphertext da un round a un altro o di scambiare i due campi.

Il formato binario include un prefisso di versione e algoritmo:

- `TC1S` per Sodium;
- `TC1G` per AES-256-GCM.

Nonce/IV e tag di autenticazione sono memorizzati insieme al ciphertext; la chiave non entra mai nel database.

## Chiave locale

`tools/ensure-local-secret.php` crea `.env.local` con un `APP_SECRET` casuale di 32 byte rappresentato in esadecimale. Non sovrascrive una chiave già presente.

`APP_SECRET` non è più definito in `.env.dev`, così il valore locale non viene sovrascritto. `.env.local` è escluso da Git. Perdere o cambiare la chiave rende indecifrabili i round già creati, perciò in un ambiente persistente deve essere sottoposta a backup sicuro separato dal database.

## Commitment pubblico

Il commitment non è una cifratura. È un impegno verificabile sul payload:

```text
twenty-choices-v1:<round-code>:<question-set-hash>:<20-bits>:<nonce-hex>
```

Il nonce impedisce di enumerare offline i soli 1.048.576 percorsi possibili.

## Ledger virtuale

Ogni round riceve esattamente un movimento:

```text
entry_type   = BANK_SEED
amount_cents = 1000000
play_id      = NULL
```

Un indice parziale impedisce due seed nello stesso round. Trigger SQLite impediscono importi diversi e rendono il ledger append-only.

## Vincoli SQLite aggiunti

M1.9.2 separa l'identità sorgente immutabile (`choice_pair_source_id_snapshot`) dal riferimento vivo opzionale `choice_pair_id`. Questo consente di eliminare in seguito una coppia regolare dal catalogo senza modificare il materiale canonico del round già aperto.

- un solo `BANK_SEED` per round;
- materiale crittografico immutabile;
- codice pubblico e jackpot iniziale immutabili;
- attivazione consentita soltanto con 20 snapshot e un seed valido;
- ledger non aggiornabile e non eliminabile;
- un solo round `ACTIVE`, già garantito dall'indice della prima migrazione.

## Superficie pubblica

La pagina `/round/{codice}` mostra soltanto:

- codice pubblico;
- stato;
- montepremio virtuale;
- numero di snapshot;
- hash delle domande;
- commitment;
- data di apertura.

Non espone ciphertext, percorso, nonce o identificatori interni.
