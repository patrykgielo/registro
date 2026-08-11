# Jak używać tego skilla w Registro

Skill pochodzi z `akin-ozer/cc-devops-skills` (Apache-2.0, patrz `LICENSE`), skopiowany w całości
z `docs/`, `scripts/` i `examples/` — sam `SKILL.md` odwołuje się do ośmiu plików w `docs/` i dwóch
w `scripts/`, więc częściowa kopia byłaby wydmuszką.

## NIE uruchamiaj `scripts/shellcheck_wrapper.sh`

Gdy `shellcheck` nie jest zainstalowany, ten wrapper **tworzy venv Pythona i doinstalowuje pakiet
w locie**. Nie mamy shellchecka ani na hoście, ani w kontenerze `app`, więc wrapper zadziałałby
zawsze — instalując coś po cichu przy każdym użyciu skilla.

Zamiast tego oficjalny obraz, zero instalacji:

```bash
docker run --rm -v "$PWD:/mnt" -w /mnt koalaman/shellcheck:stable scripts/server/*.sh
```

## Czego ten skill NIE robi

Stan na 2026-08-11: shellcheck na naszych 3 935 liniach skryptów wdrożeniowych daje **12 uwag,
zero realnych błędów** — 5×SC2329 (funkcje wołane przez trapy), 5×SC2155 (`local line="$(date)"`
w loggerach i `readonly DIR="$(cd ...)"`, świadomy styl), 2×SC1091 (`source .env`).

Ważniejsze: **shellcheck nie złapałby żadnej z ośmiu regresji, które przypięliśmy w
`tests/shell/`.** Wszystkie były behawioralne — brakujący `--entrypoint`, `docker compose`
w ścieżce recovery, sonda gubiąca kod wyjścia, cofnięty cutover TLS. Składniowo poprawne,
funkcjonalnie złe.

Ten skill jest **siatką higieniczną na nowy kod**, nie odpowiedzią na regresje. Odpowiedzią na
regresje jest `bash tests/shell/run.sh` i reguła, że każdy naprawiony błąd staje się testem.
