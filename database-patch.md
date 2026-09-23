# Database patch

The command imports the latest saved dump, then runs destination migrations. It does not take a fresh snapshot.

Local import (existing behavior):

```sh
php bin/console app:database:patch prod
```

Production to accept (one confirmation):

```sh
php bin/console app:database:patch prod --target=acc --target-project-dir=/srv/accept --non-anon
```

Accept to production (two confirmations):

```sh
php bin/console app:database:patch acc --target=prod --target-project-dir=/srv/production --non-anon
```

Both confirmations default to no. Remote targets reject non-interactive execution, and --force cannot bypass their confirmations. --force retains its existing behavior for local imports.

The initiating machine needs SSH access to the target using PATCH_ACC_SSH_HOST/USER or PATCH_PROD_SSH_HOST/USER. The target must have PHP, the application with app:database:patch, MariaDB client, gzip, and SSH/SCP access to the source. Its PATCH_* source settings must point to the correct source server and dump directory. Database credentials and migrations come from the application in --target-project-dir; verify that directory corresponds to the intended destination.

By default the latest anonymized dump is used; --non-anon selects the latest non-anonymized dump. --keep retains the downloaded dump on the destination. --document-location is supported only for local imports.
