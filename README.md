# API Gateway Log Processor

Processador CLI em Laravel para arquivos NDJSON gerados por API Gateway. O sistema lê logs linha a linha, persiste os campos relevantes no MySQL e gera relatórios CSV a partir dos dados armazenados.

## Tecnologias

- PHP 8.3
- Laravel 12
- MySQL 8
- Docker
- Docker Compose
- PHPUnit

## Arquitetura

- `Console/Commands`: entrada CLI e orquestração dos casos de uso.
- `Services`: validação de arquivo, parsing, processamento incremental e geração de relatórios.
- `Repositories`: persistência e consultas agregadas.
- `Exporters`: escrita dos arquivos CSV.
- `DTOs`: transporte de dados entre camadas.
- `Models`: representação Eloquent das tabelas.

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

Prepare o arquivo de ambiente, se ele ainda não existir.

Linux/macOS:

```bash
cp .env.example .env
```

Windows PowerShell:

```powershell
Copy-Item .env.example .env
```

Suba os containers:

```bash
docker compose up -d
```

Gere a chave da aplicação, se necessário:

```bash
docker compose exec -T app php artisan key:generate
```

Execute as migrations:

```bash
docker compose exec -T app php artisan migrate
```

## Execução Completa do Zero

Use este fluxo para recriar o banco, processar o arquivo `logs.txt` e gerar todos os relatórios.

```bash
docker compose up -d
docker compose exec -T app php artisan migrate:fresh --force
docker compose exec -T app php artisan logs:process /var/www/html/logs.txt
docker compose exec -T app php artisan reports:generate --output=storage/app/reports
```

Os arquivos CSV serão gerados no container em `/var/www/html/storage/app/reports` e, por causa do volume do Docker Compose, também aparecerão no host em:

```text
storage/app/reports/
```

Arquivos gerados:

- `consumer_requests.csv`
- `service_requests.csv`
- `service_latency_averages.csv`

## Processar Logs

O caminho informado precisa existir dentro do container. Uma forma simples é colocar o arquivo `logs.txt` na raiz do projeto, que é montada em `/var/www/html`.

```bash
docker compose exec -T app php artisan logs:process /var/www/html/logs.txt
```

O comando:

- valida se o arquivo existe e é legível;
- lê o NDJSON linha a linha;
- persiste registros válidos em lotes para reduzir escritas no banco;
- usa checkpoint por arquivo para pular linhas já processadas em execuções seguintes;
- valida o prefixo já processado para detectar truncamento ou sobrescrita do arquivo;
- ignora linhas JSON inválidas sem interromper todo o processamento;
- grava `started_at` e `created_at` a partir do timestamp original do log;
- grava `processed_at` no momento da inserção no banco;
- ignora duplicados pelo índice único composto por arquivo, linha e hash do payload;
- mostra contadores de processados, inseridos, ignorados e inválidos;
- mostra métricas de duração, throughput e pico de memória.

O tamanho do lote de insert pode ser ajustado pela variável de ambiente:

```env
LOG_PROCESSOR_BATCH_SIZE=500
```

## Gerar Relatórios

Tipos disponíveis:

- `consumers`: total de requisições por `consumer_id`;
- `services`: total de requisições por `service_name`;
- `latencies`: média de `request_latency`, `proxy_latency` e `gateway_latency` por serviço.

Gerar todos os relatórios no diretório padrão:

```bash
docker compose exec -T app php artisan reports:generate
```

Gerar todos os relatórios explicitando o diretório de saída:

```bash
docker compose exec -T app php artisan reports:generate --output=storage/app/reports
```

Gerar apenas um tipo:

```bash
docker compose exec -T app php artisan reports:generate consumers --output=storage/app/reports
docker compose exec -T app php artisan reports:generate services --output=storage/app/reports
docker compose exec -T app php artisan reports:generate latencies --output=storage/app/reports
```

O parâmetro `--output` deve ser um caminho relativo dentro do projeto. Caminhos absolutos e caminhos com `..` são rejeitados.

## Padrões de Comandos por Sistema

No Linux/macOS, os comandos podem ser executados diretamente no terminal:

```bash
docker compose exec -T app php artisan test
ls -lah storage/app/reports
```

No Windows PowerShell, use os mesmos comandos Docker e comandos nativos do PowerShell para listar arquivos:

```powershell
docker compose exec -T app php artisan test
Get-ChildItem .\storage\app\reports
```

O uso de `-T` nos comandos `docker compose exec` evita problemas de terminal interativo, especialmente em PowerShell, Git Bash, WSL e pipelines de CI.

## Testes

Localmente:

```bash
php artisan test
```

Via Docker:

```bash
docker compose exec -T app php artisan test
```

Verificação de estilo com Laravel Pint:

```bash
vendor/bin/pint --test
```

Via Docker:

```bash
docker compose exec -T app vendor/bin/pint --test
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
- `first_line_hash`
- `processed_prefix_hash`
- `source_file`
- `last_processed_line`
- `last_processed_byte_offset`
- `created_at`
- `updated_at`

Índices:

- índice único composto por `source_file_hash`, `line_number` e `payload_hash`;
- `payload_hash`;
- `consumer_id`;
- `service_name`;
- `service_id`;
- `started_at`;
- `processed_at`.

## Decisões Técnicas

### Processamento incremental

O arquivo é processado com `SplFileObject`, uma linha por vez. O sistema não usa `file_get_contents()` para carregar o arquivo inteiro. Registros válidos são persistidos em lotes, e a tabela `import_checkpoints` armazena a última linha confirmada, o offset em bytes e o hash do prefixo processado por arquivo para retomar execuções seguintes. Se o arquivo for truncado ou sobrescrito mantendo o mesmo nome, o prefixo deixa de bater e o processamento reinicia do começo.

### Timestamps

O campo `started_at` representa quando a requisição ocorreu no API Gateway. O campo `processed_at` representa quando o sistema inseriu o registro no banco. Por exigência do desafio, `created_at` também recebe o timestamp original do log, mesmo isso sendo diferente do comportamento padrão do Laravel, onde `created_at` normalmente representa o momento do insert.

### Duplicidade

Cada linha gera um `payload_hash` SHA-256. A deduplicação usa chave única composta por `source_file_hash`, `line_number` e `payload_hash`, evitando duplicidade no reprocessamento do mesmo arquivo sem descartar eventos legítimos com payload idêntico em linhas diferentes.

### Relatórios

Todos os CSVs são gerados a partir do MySQL. As agregações usam `GROUP BY` e funções nativas de banco. A escrita do CSV é feita por streaming, sem montar todo o arquivo em memória.

### Segurança

- O caminho do arquivo é validado antes da leitura.
- JSON inválido é contabilizado como inválido.
- Queries usam Query Builder.
- Exportação CSV sanitiza valores iniciados por `=`, `+`, `-` ou `@`.
- Credenciais ficam em variáveis de ambiente.

## Troubleshooting

Banco ainda iniciando:

```bash
docker compose ps
```

Recriar banco local:

```bash
docker compose exec -T app php artisan migrate:fresh --force
```

Arquivo não encontrado:

- confirme se o caminho existe dentro do container;
- se o arquivo está na raiz do projeto, use `/var/www/html/nome-do-arquivo`.

Relatórios não aparecem no host:

- confirme se o comando usou `--output=storage/app/reports`;
- confirme se o Docker Compose está usando o volume `.:/var/www/html`;
- reinicie os containers se o volume local parecer desatualizado.

Dependências ausentes no container:

```bash
docker compose exec -T app composer install
```

Serviço `app` não está rodando após mudanças no Dockerfile:

```bash
docker compose build app
docker compose run --rm --user root app composer install
docker compose up -d
```
