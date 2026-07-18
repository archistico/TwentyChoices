# Validazione delle milestone

Data: 18 luglio 2026

## M0.2.1

- Configurazione compatibile con PHPUnit 9.6.35.
- Data provider eseguiti con annotazione `@dataProvider`.
- Suite completa confermata dall'utente.

## M1.1 — Verifiche eseguite nel pacchetto

- Tutti i file PHP superano `php -l` con PHP 8.4.
- Il runner indipendente dal framework esegue 8 controlli con 0 errori.
- Le due migrazioni contengono complessivamente 41 istruzioni `up()`.
- Le migrazioni sono state eseguite in sequenza su SQLite in memoria.
- Lo schema risultante contiene 9 tabelle, 20 indici espliciti e 5 trigger.
- Il seed contiene 44 coppie regolari attive e una porta finale.
- È stato verificato che SQLite rifiuti la disattivazione e l'eliminazione della porta finale.
- È stato verificato che lo step 20 rifiuti una coppia `REGULAR`.
- Gli snapshot di dominio richiedono 19 coppie regolari distinte più la porta finale.
- L'hash dello snapshot è deterministico.

## Verifiche nell'ambiente di sviluppo

Eseguire:

```powershell
./scripts/bootstrap.ps1
```

Lo script:

1. installa o aggiorna le dipendenze Composer;
2. applica le migrazioni al database di sviluppo;
3. applica le migrazioni al database di test;
4. esegue i test indipendenti dal framework;
5. esegue PHPUnit, inclusi i test di persistenza del catalogo.

## M1.1.1 — Correzioni di compatibilità

- Rimossa l'opzione `doctrine.dbal.use_savepoints`, non riconosciuta da DoctrineBundle 3.x.
- Dichiarato `KERNEL_CLASS=App\Kernel` in `phpunit.xml.dist` per i test basati su `KernelTestCase`.
- Fissata esplicitamente la linea PHPUnit `9.6` usata dal Symfony PHPUnit Bridge.
- Dichiarato `COMPOSER_BINARY=composer` prima del bridge per evitare il warning PHP su Windows durante la ricerca di `composer.phar`.
- Resi fail-fast gli script di bootstrap: il messaggio finale di successo non viene più mostrato dopo un comando fallito.
- Il database di test viene ricreato prima delle migrazioni, rendendo deterministici i test sul seed.


## M1.1.2 — Correzione parser PowerShell

- Corretto il messaggio di errore di `Invoke-Checked`: `$LASTEXITCODE:` veniva interpretato da PowerShell come riferimento a una variabile con scope.
- Il messaggio usa ora l'operatore di formato `-f`, compatibile con Windows PowerShell 5.1 e PowerShell 7.
- Verificato che non restino interpolazioni del tipo `$variabile:` negli script PowerShell del progetto.

## M1.1.3 — Compatibilità DoctrineBundle 3 / ORM 3

- Rimossa l'opzione obsoleta `doctrine.orm.auto_generate_proxy_classes`, non accettata da DoctrineBundle 3.x.
- Rimossa la sezione `dbname_suffix` per l'ambiente di test: SQLite usa già il database separato definito in `.env.test`.
- Il bootstrap installa Composer con `--no-scripts`, quindi esegue esplicitamente `cache:clear` come primo controllo del kernel.
- In caso di configurazione Symfony o Doctrine non valida, lo script si arresta prima di migrazioni e test con un errore chiaro.


## M1.1.4 — Controllo ambiente SQLite

- Il bootstrap Windows verifica che `PDO::getAvailableDrivers()` contenga `sqlite`.
- In caso contrario mostra `PHP_BINARY` e `php_ini_loaded_file()`.
- Composer e le migrazioni non vengono avviati finché il driver non è disponibile.
- Lo script Linux/macOS applica lo stesso controllo preventivo.

## M1.1.5 — Default URI

È stato aggiunto un valore predefinito per `DEFAULT_URI` sia nei file `.env` sia negli script di bootstrap. Questo evita errori di compilazione del container Symfony in ambienti locali che contengono una configurazione router ereditata da una versione precedente.


## M1.1.6 — Connessione SQLite esplicita

- Doctrine usa direttamente `driver: pdo_sqlite`.
- Il database di sviluppo è `var/data.db`.
- Il database di test è `var/test.db`.
- `DATABASE_URL` è stato rimosso da `.env` e `.env.test`.
- Variabili di sistema o file locali residui non possono più selezionare per errore un driver PostgreSQL, MySQL o altro.
