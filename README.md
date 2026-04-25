# onda-de-promo

## Ofertas em destaque automaticas

Este projeto agora usa `offers.json` para renderizar os cards de ofertas no frontend.

Arquivos principais:
- `offers-sources.json`: lista de links de origem (afiliado/teste)
- `update-offers.php`: atualiza titulo, imagem e preco quando disponivel
- `offers.json`: saida consumida pela pagina

## Como rodar localmente

1. Edite os links em `offers-sources.json`.
2. Execute no terminal:

	php update-offers.php

3. Abra `index.html` em servidor local.

## Cron na Hostinger

1. Suba os arquivos para o public_html (ou pasta do site).
2. No painel da Hostinger, configure um Cron Job:

	/usr/bin/php /home/SEU_USUARIO/public_html/update-offers.php

3. Frequencia recomendada: a cada 10 ou 15 minutos.

## Observacoes

- O caminho mais estavel para longo prazo e usar APIs oficiais de afiliado.
- Links curtos de afiliado (ex.: `amzn.to`) devem ser mantidos em `offers-sources.json`.