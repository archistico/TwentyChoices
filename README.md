# TwentyChoices

Prototipo gratuito e simulatore tecnico di un gioco a venti scelte binarie. Ogni round usa una sola strada segreta globale; il primo percorso corretto validato dal server chiude il round e avvia il reset.

> Tutti gli importi sono virtuali. Il progetto non integra pagamenti, depositi, prelievi o premi con valore reale.

## Stato del progetto

Milestone completata: **M1.1.6 — Connessione SQLite esplicita e non sovrascrivibile**.

Il catalogo iniziale contiene 44 coppie regolari e una porta finale obbligatoria. Le prime 19 domande di un round saranno copiate dal catalogo; la ventesima sarà sempre `Porta rossa / Porta blu`.

## Requisiti

- PHP 8.3 o 8.4
- Composer
- Estensioni PHP: `ctype`, `iconv`, `pdo`, `pdo_sqlite`

Symfony 7.4 LTS richiede PHP 8.2 o superiore; il progetto impone PHP 8.3 come propria baseline.

## Installazione o aggiornamento Windows

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\bootstrap.ps1
php -S 127.0.0.1:8000 -t public
```

## Installazione o aggiornamento Linux/macOS

```bash
./scripts/bootstrap.sh
php -S 127.0.0.1:8000 -t public
```


## Errore `could not find driver`

Doctrine usa esplicitamente il driver `pdo_sqlite`: `var/data.db` per lo sviluppo e `var/test.db` per i test. La connessione non dipende più da `DATABASE_URL`, quindi file `.env.local` residui o variabili Windows non possono cambiare accidentalmente il motore del database. Prima di Composer e delle migrazioni, `bootstrap.ps1` verifica comunque che `PDO::getAvailableDrivers()` contenga `sqlite`.

Per controllare manualmente su Windows:

```powershell
where.exe php
php --ini
php -r "print_r(PDO::getAvailableDrivers());"
```

Nel file indicato come `Loaded Configuration File` devono essere abilitate almeno:

```ini
extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3
```

Se una riga inizia con `;`, rimuovere il punto e virgola. Dopo la modifica chiudere e riaprire il terminale. Composer può essere configurato con un eseguibile PHP diverso da quello risolto dal comando `php`; per questo il controllo viene eseguito sul runtime che lancerà Symfony e Doctrine.

## Verifica rapida senza Composer

Il nucleo di dominio non dipende dal framework:

```bash
php tools/domain-tests.php
```

## Endpoint

- `/` — pagina del prototipo
- `/health` — verifica JSON dell'applicazione
- `/admin/scelte` — catalogo amministrativo delle coppie
- `/admin/scelte/nuova` — creazione di una coppia regolare

## Regole M1.1

- Le coppie regolari possono essere create, modificate, attivate, disattivate ed eliminate.
- La porta finale può essere rinominata, ma non disattivata o eliminata.
- Un round richiede esattamente 19 coppie regolari attive più la porta finale.
- I dati delle domande vengono copiati in snapshot immutabili.
- L'hash SHA-256 dello snapshot entra nel commitment del round.

## Documentazione

- `docs/01-domain-specification.md`
- `docs/02-architecture.md`
- `docs/03-roadmap.md`
- `docs/04-validation.md`
- `docs/05-choice-catalog.md`

## Compatibilità dei test

La suite rimane compatibile con PHPUnit 9.6, utilizzato da Symfony PHPUnit Bridge nell'ambiente iniziale del progetto. I data provider usano l'annotazione `@dataProvider`; la configurazione XML usa `cacheResultFile` e dichiara esplicitamente `KERNEL_CLASS=App\Kernel`.

La configurazione Doctrine non usa `use_savepoints`, opzione non esposta da DoctrineBundle 3.x. Lo script `bin/phpunit` dichiara inoltre il comando Composer prima di avviare il bridge, evitando il warning `preg_replace(... null ...)` osservato su Windows con PHP 8.x.

Gli script di bootstrap interrompono immediatamente l'esecuzione se un comando fallisce e ricreano il database di test prima della suite di persistenza. In PowerShell il messaggio d'errore usa l'operatore di formato `-f`, evitando l'ambiguità sintattica di una variabile seguita da `:`.


### Correzione M1.1.3

Compatibilità con DoctrineBundle 3 / ORM 3: rimossa la configurazione legacy dei proxy e reso esplicito il controllo `cache:clear` nel bootstrap.

### Correzione M1.1.4

Il bootstrap controlla il driver PDO SQLite prima di installare dipendenze o modificare database e, in caso di errore, mostra il percorso dell’eseguibile PHP e del `php.ini` effettivamente caricati.

### Aggiornamento M1.1.5

- definita `DEFAULT_URI=http://localhost` negli ambienti di sviluppo e test;
- il bootstrap imposta la stessa variabile nel processo prima di avviare Symfony;
- la configurazione resta compatibile anche quando nella cartella locale è presente un vecchio file che usa `%env(DEFAULT_URI)%`.

### Correzione M1.1.6

- rimossa la dipendenza da `DATABASE_URL`;
- configurato direttamente `driver: pdo_sqlite`;
- configurato `var/data.db` per `dev` e `var/test.db` per `test`;
- neutralizzate eventuali variabili d'ambiente o file `.env.local` residui relativi al database.
