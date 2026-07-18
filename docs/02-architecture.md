# Architettura

## Moduli

- `Game/Domain`: percorso, commitment, montepremio virtuale e ciclo di vita del round.
- `Catalog/Domain`: coppie binarie, porta finale e snapshot immutabili.
- `Catalog/Application`: casi d'uso del catalogo amministrativo.
- `Catalog/Infrastructure`: repository DBAL e persistenza degli snapshot.
- `Controller`: adattatori HTTP Symfony.
- `migrations`: schema SQLite versionato e dati iniziali.

Il dominio non dipende da Symfony o Doctrine. I repository sono definiti come interfacce del dominio e implementati nell'infrastruttura con Doctrine DBAL.

## ULID

Le chiavi principali sono stringhe ULID da 26 caratteri. Non vengono esposti identificativi incrementali negli URL pubblici.

## Catalogo e snapshot

Il catalogo è modificabile, mentre il set di domande assegnato a un round è immutabile. `QuestionSetFactory` costruisce esattamente:

- 19 snapshot di coppie `REGULAR` attive;
- 1 snapshot `FINAL_DOOR` allo step 20.

`QuestionSetSnapshot` verifica ordine, unicità e completezza, poi calcola un hash SHA-256 della rappresentazione canonica. `DoctrineDbalQuestionSetSnapshotStore` consente una sola scrittura per round e soltanto nello stato `PREPARING`.

## Commitment

Il commitment usa SHA-256 sul payload canonico:

```text
twenty-choices-v1:<round-code>:<question-set-hash>:<20-bits>:<nonce-hex>
```

Il nonce contiene esattamente 32 byte casuali. Senza nonce, un attaccante potrebbe enumerare tutti i 1.048.576 percorsi.

## SQLite

SQLite è adatto al prototipo. Le invarianti essenziali vengono replicate con indici univoci, vincoli e trigger:

- un solo round attivo;
- una sola porta finale;
- porta finale sempre attiva e di sistema;
- porta finale non eliminabile;
- tipo corretto rispetto alla posizione dello snapshot;
- snapshot non aggiornabili.

La chiusura del round dovrà usare una transazione di scrittura acquisita prima di rileggere lo stato critico. La migrazione a PostgreSQL rimane prevista prima di qualunque ambiente con forte concorrenza.

## Tempo

Tutti i timestamp persistiti sono UTC e immutabili. Il browser mostrerà il conto alla rovescia, ma l'accettazione userà esclusivamente `available_at` e l'orologio del server.
