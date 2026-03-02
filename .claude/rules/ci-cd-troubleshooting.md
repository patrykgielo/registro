# CI/CD Troubleshooting

## Incydent 2026-02-15: Docker API version incompatibility

### Problem
```
docker: Error response from daemon: client version 1.40 is too old.
Minimum supported API version is 1.44, please upgrade your client to a newer version.
```

### Przyczyna
- GitHub Actions zaktualizowało Docker Engine na runnerach do v29.1 (9 lutego 2026)
- Docker 29 wymaga minimum API v1.44
- `getong/mariadb-action@v1.1` używało Docker client API v1.40
- Action jest porzucone (ostatni release: czerwiec 2024)

### Rozwiazanie
Zamieniono `getong/mariadb-action` na natywny `services:` block we WSZYSTKICH workflow files:

```yaml
services:
  mariadb:
    image: mariadb:10.11
    ports:
      - 3306:3306
    env:
      MYSQL_ROOT_PASSWORD: secret
      MYSQL_DATABASE: registro_test
    options: >-
      --health-cmd="mysqladmin ping -h127.0.0.1 -psecret --silent"
      --health-interval=5s
      --health-timeout=3s
      --health-retries=10
```

### Zapobieganie
- **NIGDY** nie uzywaj `getong/mariadb-action` - jest porzucone i niekompatybilne
- Zawsze uzywaj natywnych `services:` block dla baz danych w GitHub Actions
- Monitoruj github.com/actions/runner-images/issues dla breaking changes

### Pliki zmienione
- `.github/workflows/ci-staging.yml`
- `.github/workflows/test.yml`
- `.github/workflows/deploy-production.yml`

### Zrodla
- github.com/actions/runner-images/issues/13474
- docker.com/blog/docker-engine-version-29/
