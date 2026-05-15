# FacturaSolwed — Guía de instalación

## Requisitos

- Docker + Docker Compose
- 2 GB RAM mínimo
- 10 GB disco

## Instalación rápida

```bash
# 1. Clonar el repo
git clone https://github.com/SolWed-es/FacturaSolwed.git
cd FacturaSolwed

# 2. Configurar variables de entorno
cp .env.example .env
nano .env   # Edita DB_PASS, FS_API_KEY y SOLWED_LICENSE_KEY

# 3. Arrancar
docker compose -f docker-compose.prod.yml up -d

# 4. Abrir en el navegador
# http://tu-servidor:80
```

## Primera configuración

Al acceder por primera vez, el instalador de FacturaScripts se ejecutará automáticamente.

Una vez instalado, activa el plugin **SolwedPlugins** desde *Administrador → Plugins* para acceder al gestor de plugins y actualizaciones SOLWED.

## Actualizar

```bash
git pull origin master
docker compose -f docker-compose.prod.yml up -d --build
```

## Licencia y soporte

Con `SOLWED_LICENSE_KEY` configurado, SOLWED gestiona actualizaciones y monitorización remotamente.

Para obtener una licencia: soporte@solwed.es
