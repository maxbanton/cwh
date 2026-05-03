## How to contribute

#### **Did you find a bug?**

* **Ensure the bug was not already reported** by searching on GitHub under [Issues](https://github.com/maxbanton/cwh/issues).

* If you're unable to find an open issue addressing the problem, [open a new one](https://github.com/maxbanton/cwh/issues/new). Be sure to include a **title and clear description**, as much relevant information as possible, and a **code sample** or an **executable test case** demonstrating the expected behavior that is not occurring.

#### **Did you write a patch that fixes a bug?**

* Open a new GitHub pull request with the patch.

* Ensure the PR description clearly describes the problem and solution. Include the relevant issue number if applicable.

#### **Did you fix whitespace, format code, or make a purely cosmetic patch?**

Changes that are cosmetic in nature and do not add anything substantial to the stability, functionality, or testability will generally not be accepted.

#### **Local development**

Use the Makefile targets — they run inside Docker against the supported PHP matrix and exactly match what CI runs. Direct `phpunit`/`phpcs`/`phpstan` invocations on your host may pick up a different PHP version or dependency set:

```bash
make build-svc PHP=8.4    # build the dev container
make install   PHP=8.4    # composer install inside it
make lint                 # PSR-12 lint via phpcs
make lint-fix             # auto-fix PSR-12 violations via phpcbf
make analyse              # phpstan level 8
make test                 # PHPUnit
make matrix               # run install + lint + test across PHP 8.1–8.5 in parallel
```

Thanks!
