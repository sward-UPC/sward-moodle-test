# Makefile — atajos para el entorno Moodle de pruebas de SWARD.
.DEFAULT_GOAL := help
SHELL := /bin/bash

MOODLE_URL ?= http://localhost:8090
MOODLE_CT ?= sward-moodle-app

.PHONY: help up down restart logs ps wait config cursos sitio token clean

help: ## Muestra esta ayuda
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

up: ## Levanta Moodle + MariaDB en segundo plano
	docker compose up -d

down: ## Detiene los contenedores (conserva volumenes/datos)
	docker compose down

restart: ## Reinicia los contenedores
	docker compose restart

logs: ## Sigue los logs de Moodle
	docker compose logs -f moodle

ps: ## Estado de los contenedores
	docker compose ps

config: ## Valida el docker-compose.yml
	docker compose config

wait: ## Espera a que Moodle termine el bootstrap (puede tardar minutos)
	@echo "Esperando a que Moodle responda en $(MOODLE_URL)/login/index.php ..."
	@until curl -fsS -o /dev/null "$(MOODLE_URL)/login/index.php"; do \
		printf '.'; sleep 5; \
	done; \
	echo ""; echo "Moodle esta listo: $(MOODLE_URL)"

cursos: ## Carga Estadistica y Matematica Financiera (idempotente)
	python seed/validacion/generar.py
	docker cp seed/validacion/salida/cursos.json $(MOODLE_CT):/tmp/cursos.json
	docker cp seed/validacion/cargar_cursos.php $(MOODLE_CT):/tmp/cargar_cursos.php
	docker exec $(MOODLE_CT) php /tmp/cargar_cursos.php /tmp/cursos.json

sitio: ## Aplica los ajustes del sitio para los participantes
	docker cp seed/validacion/configurar_sitio.php $(MOODLE_CT):/tmp/configurar_sitio.php
	docker exec $(MOODLE_CT) php /tmp/configurar_sitio.php
	docker exec $(MOODLE_CT) php /var/www/html/admin/cli/purge_caches.php

token: ## Recuerda como generar el token (paso manual en la UI)
	@echo "El token se genera desde la UI de Moodle:"
	@echo "  $(MOODLE_URL)/admin/settings.php?section=webservicetokens"
	@echo "Ver el README (seccion 'Habilitar Web Services y generar token')."

clean: ## Detiene y ELIMINA los volumenes (borra todos los datos)
	docker compose down -v
