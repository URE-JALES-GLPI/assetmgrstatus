# Asset Maintenance & Status

Plugin GLPI para **registro, acompanhamento e gerenciamento de manutenções de ativos**, com controle de transferências entre URE e escolas e painel dedicado ao técnico.

---

## Funcionalidades

### Manutenção (`front/maintenance.php`)
* Registro de manutenções preventivas e corretivas com status (estoque, ativo, inativo, garantia, inservível, manutenção).
* Filtros por tipo de ativo, status, componente afetado e busca.
* Visualização em lista ou grade.
* Alteração em massa de status.
* Upload de até 3 fotos por manutenção.
* Registro de componentes afetados com descrição.
* Prazo de retorno previsto com alerta de manutenções há mais de 60 dias.
* Desfazer alteração de status (janela de 48h).
* Aba própria no ativo do GLPI com histórico e componentes.

### Transferência (via Inventário)
* Transferência de ativos entre URE e escolas direto pela barra de ação em massa do **Inventário** (botão **Transferir** ao lado de **Alterar Status em Massa**).
* Ciclo completo: **pendente → em manutenção → pronto → finalizado**.
* Bloqueio do ativo durante a transferência (impede edição no inventário).
* Aplicação definitiva dos status finais no inventário ao finalizar.
* Termos PDF de retirada e devolução (`transfer_pdf.php`).

### Painel do Técnico (`front/tecnico.php`)
* Cards de transferência com cronômetro em tempo real por etapa.
* Ações: Pegar, Diário, Pronto, Finalizar.
* Filtros por status, técnico, dia específico e ordenação (mais recente / mais antigo).
* Atualização automática via AJAX a cada 10s com aviso de nova transferência.

### Diário do técnico (`front/tecnico_diario.php`)
* Acompanhamento item a item do que foi realizado.
* Quick actions e marcação de componentes como resolvidos.
* Barra de progresso e conclusão/reabertura por item.

### Dashboard (`front/dashboard.php`)
* Cards por status, alertas de +60 dias, manutenções/baixas do mês.
* Gráfico de evolução de 6 meses e lista de alertas.

### Relatórios e exportação (`front/reports.php`, `front/export.php`, `front/export_mensal.php`)
* 6 modos: lista de ativos, histórico, por técnico, por entidade, ranking de componentes, tempo médio em manutenção.
* Exportação CSV (Excel) e PDF.
* Relatório mensal XLSX (padrão da Secretaria) com 4 abas.

### Histórico e logs
* Tabela de histórico com tipos (mudança de status, manutenção realizada, baixa, nota).
* Log de visualizações e mini timeline no modal de histórico.

---

## Instalação

1. Copie a pasta do plugin para `glpi/plugins/assetmgrstatus`.
2. Acesse **Configuração → Plugins** no GLPI e instale o plugin.
3. Ative o plugin e configure os perfis (**Configuração → Perfis → aba do plugin**).

Requisitos: GLPI 10.0 a 12.0, PHP 8.x.

---

## Perfis e direitos

* `plugin_assetmgrstatus` — acesso geral ao plugin (menu e telas).
* `plugin_assetmgrstatus_tecnico` — acesso ao painel do técnico.
* `plugin_assetmgrstatus_transfer` — acesso ao módulo de transferências.

---

## Estrutura

* `front/` — telas e formulários.
* `ajax/` — endpoints de atualização via AJAX.
* `src/` — classes principais: `MaintenanceRecord`, `Transfer`, `Stats`.
* `inc/` — integração com menu e perfil do GLPI.
* `public/` — CSS e JavaScript.

---

## Contribuições

Contribuições são bem-vindas. Caso encontre algum problema ou tenha sugestões de melhoria, abra uma **Issue** ou envie um **Pull Request**.

---

## Licença

Este projeto é distribuído sob a licença **GPL v2+**.

---

## Autor

Desenvolvido por **Leonardo Poiatti Fação**.