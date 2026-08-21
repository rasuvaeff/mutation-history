# rasuvaeff/mutation-history

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/mutation-history/v)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/mutation-history/downloads)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![Build](https://github.com/rasuvaeff/mutation-history/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/mutation-history/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/mutation-history/php)](https://packagist.org/packages/rasuvaeff/mutation-history)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

История между прогонами для мутационного тестирования
[Infection](https://infection.github.io/). Infection одноразовый: прогнал,
прочитал MSI, лог выброшен. Этот пакет парсит per-mutant JSON-лог Infection в
[`quality-ledger`](https://github.com/rasuvaeff/quality-ledger), давая тренд
MSI между сборками и diff между любыми двумя прогонами, который отличает
мутанта, **только что начавшего убегать** (реальная регрессия), от того, кто
**убегает всегда** (фоновый долг, который не связанный PR не создавал).

> Используете AI-ассистента? [llms.txt](llms.txt) содержит компактный API-справочник, который можно передать модели.

## Почему не просто `--min-msi`?

`infection --min-msi=85` блокирует любой PR, уронивший агрегат ниже
фиксированного числа — включая тот, что не трогает ничего рядом с долгом.
`--git-diff-lines` сравнивает только текущий diff, не историю. Ни один не
помнит «этот конкретный мутант убегает уже 40 прогонов» против «этот только
начал сегодня». `mutation-history` добавляет ровно эту память поверх
`RatchetGate` из [`quality-ledger`](https://github.com/rasuvaeff/quality-ledger),
падающего только на реально новую регрессию.

У Infection нет поля `killedBy`/`coveredByTests` в логе, поэтому per-test
killing power (какой тест убил мутанта, какой тест никого не убивает) — не то,
что этот пакет может дать только из лога; это задел на будущее через парсинг
`processOutput`, не часть ядра.

## Требования

- PHP 8.3–8.5
- `rasuvaeff/quality-ledger`
- Infection, настроенный писать JSON-лог: `"logs": { "json":
  "build/infection-log.json" }` в `infection.json5` (CLI-флага
  `--logger-json` не существует — только конфиг)

## Установка

```bash
composer require rasuvaeff/mutation-history
```

## Использование

```php doc-exec
use Rasuvaeff\MutationHistory\InfectionLogParser;
use Rasuvaeff\MutationHistory\MutantId;
use Rasuvaeff\QualityLedger\Ledger;
use Rasuvaeff\QualityLedger\LocalFileStorage;
use Rasuvaeff\QualityLedger\RatchetGate;

// Два прогона одного мутанта: сначала убит, потом убегает. В реальной
// работе это байты собственного JSON-лога Infection.
$mutant = [
    'mutator' => ['mutatorName' => 'TrueValue', 'originalFilePath' => 'src/RetryPolicy.php', 'originalStartLine' => 42],
    'diff' => "@@ -42,1 +42,1 @@\n-        return \$this->attempts < \$this->max;\n+        return true;",
];
$before = json_encode(['killed' => [$mutant]]);
$after = json_encode(['escaped' => [$mutant]]);

$parser = new InfectionLogParser();
$ledger = new Ledger(
    id: new MutantId(),
    storage: new LocalFileStorage(sys_get_temp_dir() . '/mutation-history-readme'),
);

$ledger->append($parser->parse(json: $before, run: 'run-1', ts: 1_700_000_000, scope: 'acme/widgets', metrics: ['msi' => 100.0]));
$ledger->append($parser->parse(json: $after, run: 'run-2', ts: 1_700_003_600, scope: 'acme/widgets', metrics: ['msi' => 50.0]));

$isBad = static fn(string $status): bool => $status === 'escaped';
$diff = $ledger->diff(scope: 'acme/widgets', base: 'run-1', head: 'run-2', isBad: $isBad);
$gate = (new RatchetGate())->evaluate($diff);

count($diff->newBad);                // => 1
$gate->ok;                           // => false
$gate->regressions[0]->meta['file']; // => 'src/RetryPolicy.php'

$ledger->trend(scope: 'acme/widgets', metric: 'msi')->points[1]->value; // => 50.0
```

Блок выше исполняется на каждой сборке (`composer docs`, через
[doc-exec](https://github.com/rasuvaeff/doc-exec)) — комментарии `// =>` это
ассерты, а не украшение, поэтому пример не может разойтись с кодом.

В настоящей CI-джобе лог берётся с диска, идентичность прогона — из
окружения, а упавший гейт завершает джобу:

```php
$report = $parser->parse(
    json: file_get_contents('build/infection-log.json'),
    run: getenv('GITHUB_SHA') ?: 'local',
    ts: time(),
    scope: 'acme/widgets',
);
$ledger->append($report);

$diff = $ledger->diff(scope: 'acme/widgets', base: $previousSha, head: $currentSha, isBad: $isBad);

if (!(new RatchetGate())->evaluate($diff)->ok) {
    exit(1);
}
```

### `bin/mutation-history`

Пакет поставляет CLI — `vendor/bin/mutation-history` — именно так его обычно
потребляет CI-джоба. Это тонкая обёртка над API выше: разбор `$argv` руками,
без консольного фреймворка и без файла конфигурации.

| Команда | Что делает | Код возврата |
|---|---|---|
| `digest --scope=<s> --run=<id> --log=<path>` | Разбирает один JSON-лог Infection и дописывает его как прогон | `0`; `1` при отсутствующей опции, нечитаемом логе или некорректном значении |
| `diff --scope=<s> --base=<run> --head=<run>` | Печатает строки `NEW`/`OLD`/`FIXED` и итоговую строку | `1`, когда падает ratchet-гейт (мутант стал плохим), иначе `0` |
| `trend --scope=<s>` | Печатает `run<TAB>значение` по каждому записанному прогону | `0` |
| `badge --scope=<s>` | Рисует последнее значение метрики SVG-бейджем — в `--out` или в stdout | `0`; `1` при незаписываемом `--out` |

| Опция | Где | По умолчанию | Примечание |
|---|---|---|---|
| `--storage=<dir>` | везде | `<cwd>/build/mutation-history` | Где лежит файл ledger'а |
| `--msi=<число>` | `digest` | не записывается | Обязано быть числом — опечатка это ошибка, а не `0.0`, записанный в append-only историю |
| `--bad-status=<status>` | `diff` | `escaped` | Пустое значение откатывается к умолчанию, а не объявляет «плохих» статусов нет |
| `--metric=<имя>` | `trend` | `msi` | |
| `--window=<n>` | `trend` | все прогоны | Неотрицательное целое; `0` даёт ноль точек |
| `--metric=<имя>` | `badge` | `msi` | |
| `--label=<текст>` | `badge` | имя метрики | Левая половина бейджа |
| `--out=<путь>` | `badge` | stdout | SVG самодостаточен: без shields.io и без сети |

Любое некорректное значение опции отвергается с сообщением в stderr и кодом
`1`. Для гейта это осознанно: опасен не сбой, а тихий зелёный прогон.

```yaml
- name: Mutation history
  run: |
    vendor/bin/infection --threads=max
    vendor/bin/mutation-history digest --scope=acme/widgets --run="$GITHUB_SHA" \
      --log=build/infection-log.json --msi="$(jq .stats.msi build/infection-log.json)"
    vendor/bin/mutation-history diff --scope=acme/widgets --base="$BASE_SHA" --head="$GITHUB_SHA"
```

Ledger — обычный файл: сохраняйте `build/mutation-history/` между прогонами
(кэш, артефакт, закоммиченный каталог — пакету всё равно), и тогда `diff`
будет с чем сравнивать.

`badge` рисует через `BadgeSvg` из `quality-ledger`, поэтому файл и есть весь
артефакт — публикуйте его туда, откуда его достанет README:

```bash
vendor/bin/mutation-history badge --scope=acme/widgets --out=build/msi.svg
```

Скоуп без прогонов рисуется как `n/a` красным и всё равно даёт код `0`:
первая джоба репозитория — ровно это состояние, и ронять на нём сборку
бессмысленно.

### В GitHub Actions

Этот репозиторий гейтит сам себя рецептом ниже — см.
[`.github/workflows/build.yml`](.github/workflows/build.yml) и
[`.github/scripts/mutation-gate.sh`](.github/scripts/mutation-gate.sh).

```yaml
# Раздельные restore/save, а не один шаг `actions/cache`: комбинированный
# объявляет `post-if: success()` и потому не сохранил бы результат ровно на
# том красном прогоне, который записал регрессию. `run_attempt` в ключе нужен
# потому, что при re-run `run_id` повторяется, а `save` отказывается писать
# в занятый ключ.
- name: Restore mutation history
  uses: actions/cache/restore@<sha> # v6
  with:
    path: build/mutation-history
    key: mutation-history-${{ github.run_id }}-${{ github.run_attempt }}
    restore-keys: mutation-history-

- name: Mutation testing
  run: composer mutation

- name: Ratchet gate on new escapes
  if: ${{ !cancelled() }}   # прогон ниже minMsi — как раз тот, что стоит записать
  run: composer mutation:gate

- name: Save mutation history
  if: ${{ !cancelled() }}
  uses: actions/cache/save@<sha> # v6
  with:
    path: build/mutation-history
    key: mutation-history-${{ github.run_id }}-${{ github.run_attempt }}
```

Два следствия кэширования, о которых стоит знать до копирования:

- **Храповик затягивается только на основной ветке.** Pull request читает
  историю базовой ветки, но пишет в собственный изолированный кэш: он
  сравнивается с последним прогоном базовой ветки и не может её испортить.
- **История живёт ровно столько, сколько кэш.** GitHub вычищает записи после
  семи дней без чтений, и следующий прогон становится новой точкой отсчёта.
  Если история должна это пережить — сохраняйте её в другое место: `--storage`
  принимает любой каталог, а леджер — это один JSON-файл на scope.

В идентичность мутанта входит `originalFilePath` ровно в том виде, в каком его
отдаёт Infection, то есть **абсолютным**. У леджера, набранного в
`/home/runner/work/...`, нет общих id с набранным в `/app`, поэтому держите
одно хранилище на окружение (или гоняйте гейт только в CI, как здесь), а не
подмешивайте локальный прогон в историю CI.

### `InfectionLogParser`

Превращает JSON-лог в `Rasuvaeff\QualityLedger\RunReport`. Каждый мутант из
`killed`, `killedByStaticAnalysis`, `escaped`, `timeouted`, `errored` и
`uncovered` и `ignored` становится `Datum` с `meta: ['file', 'line',
'mutator', 'diff']` — `killedByStaticAnalysis` под статусом `killed`,
остальные под одноимённым (`timeouted` → `timeout`, `errored` → `error`).
Любой из них годится в `--bad-status`.
Записи `syntaxErrors` отбрасываются (артефакт тулинга, не мутант).

### `MutantId`

`sha256(len(file) + ":" + file + ":" + line + ":" + len(mutatorName) + ":" +
mutatorName + ":" + normalizedDiff)`. Длины делают кодирование инъективным:
обычному разделителю пришлось бы быть байтом, которого не может быть внутри
поля, а JSON-лог несёт любой байт в любом имени. `normalizedDiff` убирает номера строк из hunk-заголовков (уже несёт их
отдельно `line`) и схлопывает пробелы — косметическая переформатировка
окружающего кода не чеканит новый id; сама мутация (`+`/`-`) не трогается.

## Безопасность

Библиотека не читает окружение: вы передаёте байты JSON-лога и идентичность
прогона. Лог считается недоверенным входом — `json_decode` идёт с
`JSON_THROW_ON_ERROR`, и каждое поле каждой записи проверяется до
использования, поэтому обрезанный лог или лог чужой версии даёт
`InvalidArgumentException` с именем поля, а не `TypeError` из глубины.

CLI читает `getcwd()` (для умолчания `--storage`) и путь из `--log`. Лог
загружается **целиком**: `file_get_contents()` плюс `json_decode()` на
большом логе Infection требуют в несколько раз больше памяти, чем размер
файла, поэтому джобе, гейтящей пакет с десятками тысяч мутантов, нужен
`memory_limit` заметно выше размера лога. Потокового режима и ограничения
размера нет.

## Примеры

См. [examples/](examples/).

## Разработка

PHP/Composer на хосте нет — всё через Docker (`composer:2` image).

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

## Лицензия

[BSD-3-Clause](LICENSE.md)
