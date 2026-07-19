# Checklist release demo

## Prima di esporre una demo

- [ ] `APP_ENV=prod`.
- [ ] `APP_SECRET` casuale, lungo, privato e non versionato.
- [ ] HTTPS obbligatorio sul reverse proxy.
- [ ] `DEFAULT_URI` impostato sull'origin HTTPS reale.
- [ ] `ADMIN_ALLOWED_IPS` ristretto alle reti amministrative necessarie.
- [ ] Creato almeno un `SUPER_ADMIN` con password unica.
- [ ] Creato un account separato per ogni operatore/auditor; nessun account condiviso.
- [ ] Verificato `php bin/console app:system:check`.
- [ ] Backup SQLite coerente provato con ripristino e `PRAGMA integrity_check`.
- [ ] `var/`, `.env.local` e database esclusi dalla document root.
- [ ] Rate limit aggiuntivo configurato sul reverse proxy/WAF.
- [ ] Rotazione e retention di `var/log/security.jsonl` configurate.
- [ ] Header HTTPS/HSTS verificati dall'esterno.
- [ ] Nessun account demo con password predefinita.
- [ ] Nessun importo reale, pagamento o premio convertibile abilitato.

## Comandi di verifica

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:system:check
php tools/domain-tests.php
php bin/phpunit
```

## Primo amministratore

```bash
php bin/console app:admin:create admin --role=SUPER_ADMIN
```

La password viene richiesta in modo nascosto se `--password` non è specificata.

## Configurazione demo remota

L'autenticazione non sostituisce l'allowlist IP. Per una demo remota mantenere entrambe:

1. autenticazione individuale;
2. `ADMIN_ALLOWED_IPS` limitato a VPN, IP statici o rete amministrativa;
3. TLS terminato da reverse proxy affidabile;
4. backup automatici e monitoraggio spazio disco;
5. nessuna esposizione diretta del server PHP built-in su Internet.

Il server `php -S` resta destinato esclusivamente allo sviluppo locale.

## Gate M1.9 prima di M2.1

La checklist di release tecnica non sostituisce la fase M1.9. Prima di riprendere lo sviluppo di M2.1 devono essere completate e validate in sequenza tutte le milestone M1.9.1–M1.9.15 definite in `docs/15-verification-hardening-plan.md`.

In particolare il gate finale richiede una full journey da installazione pulita fino a vincita, settlement, reset, credito, nuovo round, verifica pubblica, riconciliazione ledger, audit, diagnostica e restart.


## Gate M1.9.4

Prima di promuovere M1.9.4 eseguire da una working copy valida:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\scripts\verify-m1.9.4.1.ps1
```

Il comando deve completare la regressione M1.9.3, la suite completa e il gate `app:verification:play-start-accounting --env=test` senza errori.
