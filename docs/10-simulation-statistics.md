# M1.6 — Simulazione, statistiche e amministrazione

## Obiettivo

M1.6 introduce un laboratorio statistico separato dal gioco reale. Serve a studiare come distribuzioni diverse delle scelte A/B concentrano o disperdono i giocatori nello spazio dei `2^20 = 1.048.576` percorsi.

Le simulazioni non sono giocate e non devono mai essere confuse con esse.

## Isolamento dal dominio reale

Una simulazione:

- non crea record `play` o `play_step`;
- non crea o chiude `game_round`;
- non modifica `ledger_entry`;
- non incrementa il montepremio;
- non crea crediti o ricevute;
- non scrive nella catena `audit_event` del gioco reale;
- non legge né decifra la strada segreta di un round attivo.

I dati vengono salvati esclusivamente nelle tabelle `simulation_*`.

## Profili sintetici

Sono disponibili tre profili:

### `UNIFORM`

Ogni step sceglie A/B al 50/50. È il riferimento matematico neutro.

### `FIXED_A_BIAS`

Ogni step usa la stessa probabilità configurabile di A, dal 50% al 95%.

Esempio: con 70%, ogni scelta ha `P(A)=0,70` e `P(B)=0,30`.

### `ALTERNATING_BIAS`

La preferenza configurata viene alternata:

- step dispari: preferenza verso A;
- step pari: preferenza complementare verso B.

Con bias 70%:

- step 1: A 70% / B 30%;
- step 2: A 30% / B 70%;
- e così via.

Questi profili sono **sintetici**. Non rappresentano stime empiriche del comportamento umano e non devono essere presentati come tali.

## Riproducibilità

Ogni simulazione salva un seed intero. Il generatore usa `Random\\Engine\\Mt19937` esclusivamente per la simulazione statistica.

A parità di:

- numero di giocate;
- profilo;
- bias;
- seed;

il risultato è riproducibile.

Il generatore della simulazione non viene mai usato per generare la strada segreta reale, che continua a usare primitive crittograficamente sicure.

## Algoritmo di copertura

Lo spazio completo contiene 1.048.576 percorsi. Per sapere se un percorso è già comparso viene usato un bitset da:

```text
1.048.576 bit = 131.072 byte = 128 KiB
```

Questo consente di calcolare esattamente:

- percorsi distinti;
- giocate duplicate;
- percentuale di copertura;

senza mantenere un array PHP con oltre un milione di elementi.

Per i percorsi già duplicati viene mantenuto un contatore esatto; nel database vengono persistiti soltanto i 50 percorsi più frequenti.

## Metriche della simulazione

Per ogni run vengono registrati:

- numero di giocate;
- seed;
- profilo e bias;
- percorsi distinti;
- giocate duplicate;
- copertura dello spazio totale in ppm;
- entropia empirica di Shannon;
- numero effettivo di percorsi osservati `2^H`;
- massima frequenza di un singolo percorso;
- distribuzione A/B per ciascuno dei 20 step;
- top 50 percorsi più frequenti;
- durata computazionale della simulazione.

L'entropia è quella della distribuzione empirica osservata. Con un campione piccolo non può raggiungere 20 bit, perché è limitata dal numero di percorsi effettivamente osservati.

## Metriche del gioco reale

La dashboard mostra separatamente metriche derivate dalle tabelle reali:

- giocate totali;
- vinte;
- perse;
- interrotte/accreditate;
- percorsi completi distinti;
- durata media delle giocate completate;
- durata media dei round conclusi;
- contributi virtuali accumulati nel jackpot;
- quota organizzativa virtuale aggregata.

Queste metriche sono read-only e non alterano il dominio.

## Persistenza

### `simulation_run`

Contiene il riepilogo immutabile della simulazione.

### `simulation_choice_stat`

Contiene 20 righe per run, una per step, con:

- probabilità A configurata;
- conteggio A osservato;
- conteggio B osservato.

### `simulation_path_stat`

Contiene al massimo 50 percorsi duplicati, ordinati per frequenza.

I riepiloghi e le statistiche sono immutabili mediante trigger SQLite.

## Interfaccia amministrativa

Endpoint:

```text
/admin/simulazioni
/admin/simulazioni/{codice}
/admin/simulazioni/{codice}/csv
```

Dal browser è imposto un limite di 250.000 giocate per evitare richieste HTTP troppo lunghe.

Per volumi maggiori è disponibile la CLI:

```bash
php bin/console app:simulation:run \
  --plays=1000000 \
  --profile=UNIFORM \
  --seed=20260718
```

Il limite applicativo della CLI è 1.000.000 di giocate per singolo run.

## Esportazione CSV

Ogni run può essere esportato in CSV con:

1. riepilogo;
2. distribuzione A/B dei 20 step;
3. percorsi più frequenti.

Il CSV contiene dati aggregati della simulazione e nessun identificativo di giocatore.
