# API Gateway Log Processor

Processador CLI em Laravel para arquivos NDJSON gerados por API Gateway. O sistema le logs linha a linha, persiste os campos relevantes no MySQL e gera relatorios CSV a partir do banco.

## Tecnologias

- PHP 8.3
- Laravel 12
- MySQL 8
- Docker
- Docker Compose
- PHPUnit

## Arquitetura

- `Console/Commands`: entrada CLI, apenas orquestracao.
- `Services`: validacao de arquivo, parser, processamento e geracao de relatorios.
- `Repositories`: persistencia e consultas agregadas.
- `Exporters`: escrita dos CSVs.
- `DTOs`: transporte dos dados parseados e do resumo de processamento.
- `Models`: representacao Eloquent da tabela principal.

## Estrutura

```text
app/
  Console/Commands
  DTOs
  Exporters
  Models
  Repositories
  Services
database/migrations
tests/Feature
tests/Unit
```

## Instalação com Docker

Prepare o arquivo de ambiente, se ele ainda nao existir:

```bash
cp .env.example .env
```

Suba os containers:

```bash
docker compose up -d
```

Gere a chave da aplicacao, se necessario:

```bash
docker compose exec app php artisan key:generate
```

Execute as migrations:

```bash
docker compose exec app php artisan migrate
```

## Processar Logs

O caminho do arquivo deve existir dentro do container. Uma forma simples e colocar o arquivo no diretorio do projeto, que e montado em `/var/www/html`.

```bash
docker compose exec app php artisan logs:process /var/www/html/logs.txt
```

O comando:

- valida se o arquivo existe e e legivel;
- le o NDJSON linha a linha;
- persiste registros validos em lotes para reduzir escritas no banco;
- usa checkpoint por arquivo para pular linhas ja processadas em execucoes seguintes;
- valida o prefixo ja processado para detectar truncamento ou sobrescrita do arquivo;
- ignora linhas JSON invalidas sem parar todo o processamento;
- grava `started_at` e `created_at` a partir do log;
- grava `processed_at` no momento da insercao;
- ignora duplicados pelo indice unico composto por arquivo, linha e hash do payload;
- mostra contadores de processados, inseridos, ignorados e invalidos.

## Gerar Relatórios

Tipos disponiveis:

- `consumers`: total de requisicoes por `consumer_id`;
- `services`: total de requisicoes por `service_name`;
- `latencies`: media de `request_latency`, `proxy_latency` e `gateway_latency` por servico.

Gerar todos:

```bash
docker compose exec app php artisan reports:generate
```

Gerar por tipo:

```bash
docker compose exec app php artisan reports:generate consumers
docker compose exec app php artisan reports:generate services
docker compose exec app php artisan reports:generate latencies
```

Diretorio de saida customizado:

```bash
docker compose exec app php artisan reports:generate latencies --output=storage/app/reports
```

## Testes

Localmente:

```bash
php artisan test
```

Via Docker:

```bash
docker compose exec app php artisan test
```

## Banco de Dados

Tabela principal: `processed_logs`.

Campos principais:

- `consumer_id`
- `service_id`
- `service_name`
- `request_method`
- `request_uri`
- `response_status`
- `request_latency`
- `proxy_latency`
- `gateway_latency`
- `client_ip`
- `started_at`
- `processed_at`
- `payload_hash`
- `source_file_hash`
- `source_file`
- `line_number`
- `created_at` (mesmo instante de `started_at`)
- `updated_at`

Tabela de controle incremental: `import_checkpoints`.

Campos principais:

- `source_file_hash`
- `processed_prefix_hash`
- `source_file`
- `last_processed_line`
- `last_processed_byte_offset`
- `created_at`
- `updated_at`

Indices:

- indice unico composto por `source_file_hash`, `line_number` e `payload_hash`;
- `payload_hash`;
- `consumer_id`;
- `service_name`;
- `service_id`;
- `started_at`;
- `processed_at`;

## Decisões Técnicas

### Processamento incremental

O arquivo e processado com `SplFileObject`, uma linha por vez. O sistema nao usa `file_get_contents()` para carregar o arquivo inteiro. Registros validos sao persistidos em lotes, e a tabela `import_checkpoints` armazena a ultima linha confirmada, o offset em bytes e o hash do prefixo processado por arquivo para retomar execucoes seguintes. Se o arquivo for truncado ou sobrescrito mantendo o mesmo nome, o prefixo deixa de bater e o processamento reinicia do comeco.

### Duplicidade

Cada linha gera um `payload_hash` SHA-256. A deduplicacao usa chave unica composta por `source_file_hash`, `line_number` e `payload_hash`, evitando duplicidade no reprocessamento do mesmo arquivo sem descartar eventos legitimos com payload identico em linhas diferentes.

### Relatórios

Todos os CSVs sao gerados a partir do MySQL. As agregacoes usam `GROUP BY` e funcoes nativas de banco. A escrita do CSV e feita por streaming, sem montar todo o arquivo em memoria.

### Segurança

- O caminho do arquivo e validado antes da leitura.
- JSON invalido e contabilizado como invalido.
- Queries usam Query Builder.
- Exportacao CSV sanitiza valores iniciados por `=`, `+`, `-` ou `@`.
- Credenciais ficam em variaveis de ambiente.

## Troubleshooting

Banco ainda iniciando:

```bash
docker compose ps
```

Recriar banco local:

```bash
docker compose exec app php artisan migrate:fresh
```

Arquivo nao encontrado:

- confirme se o caminho existe dentro do container;
- se o arquivo esta na raiz do projeto, use `/var/www/html/nome-do-arquivo`.

Dependencias ausentes no container:

```bash
docker compose exec app composer install
```

Servico `app` nao esta rodando apos mudancas no Dockerfile:

```bash
docker compose build app
docker compose run --rm --user root app composer install
docker compose up -d
```
