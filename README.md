# Chatea con tu Empresa — Agente Local

> Conecta tu base de datos privada al SaaS **Chatea con tu Empresa** sin exponer tu IP pública ni abrir puertos entrantes.

---

## ¿Qué es el agente?

El agente es un pequeño programa que se instala en tu servidor o equipo. Se conecta a tu base de datos local y abre una conexión **saliente** hacia la plataforma — nunca al revés.

```
Tu base de datos → Agente → SaaS (Chatea con tu Empresa)
```

Solo ejecuta consultas de lectura enviadas por la plataforma. Nunca permite escrituras remotas.

---

## Elige tu agente

### Escritorio (con interfaz gráfica)

Para Windows, macOS o Linux con entorno de escritorio.

| Sistema | Descarga |
|---------|----------|
| 🪟 Windows 10/11/Server | [ChateaAgent-Setup.exe](https://github.com/chateacontuempresa/agent/releases/latest/download/ChateaAgent-Setup.exe) |
| 🍎 macOS (Intel + Apple Silicon) | [ChateaAgent.dmg](https://github.com/chateacontuempresa/agent/releases/latest/download/ChateaAgent.dmg) |
| 🐧 Linux (Ubuntu, Debian, CentOS) | [ChateaAgent.AppImage](https://github.com/chateacontuempresa/agent/releases/latest/download/ChateaAgent.AppImage) |

→ Ver todos los releases: [github.com/chateacontuempresa/agent/releases](https://github.com/chateacontuempresa/agent/releases)

---

### Servidor / Hosting (sin interfaz gráfica)

Para VPS, cPanel, Plesk o cualquier servidor sin escritorio.

| Agente | Entorno | Documentación |
|--------|---------|---------------|
| 🐍 Python | VPS · Linux · cualquier SO | [latest/python/](./latest/python/) |
| 🌐 PHP | cPanel · Plesk · hosting compartido | [latest/php/](./latest/php/) |
| 📦 Node.js | VPS · Railway · Render · Docker | [latest/nodejs/](./latest/nodejs/) |

La carpeta `latest/` siempre contiene la versión más reciente con documentación completa.

---

## Bases de datos soportadas

| Base de datos | Escritorio | Python | PHP | Node.js |
|--------------|:----------:|:------:|:---:|:-------:|
| MySQL / MariaDB | ✅ | ✅ | ✅ | ✅ |
| PostgreSQL | ✅ | ✅ | ✅ | ✅ |
| SQLite | ✅ | ✅ | ❌ | ✅ |
| SQL Server / MSSQL | ✅ | ❌ | ❌ | ✅ |

---

## Instalación en 4 pasos

1. Descarga el agente para tu sistema (tabla de arriba)
2. En la plataforma: **Fuentes → Conectar BD → Agente local**
3. Copia el **token de instalación** que genera la plataforma
4. Ejecuta el agente y pega el token cuando te lo pida

---

## Versiones anteriores

Todas las versiones están disponibles en carpetas numeradas:

```
agent/
├── latest/       ← versión más reciente
├── v1.0.5/
│   ├── python/
│   ├── php/
│   └── nodejs/
└── ...
```

→ [Ver todas las versiones](https://github.com/chateacontuempresa/agent/releases)

---

## ¿Necesitas ayuda?

Abre un [issue](https://github.com/chateacontuempresa/agent/issues) en este repositorio.

---

*Este repositorio es público para que cualquier usuario pueda descargar el agente sin necesidad de autenticación.*
