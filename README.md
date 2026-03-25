# kiri 霧

> *霧 — mist, fog* — light, ephemeral, cuts through your data silently.

A minimal Ruby/Sinatra REST API for managing CSV files from the terminal.
Two files, no database, no nonsense.

| File | Role | Where |
|------|------|-------|
| `kirid` | server — Sinatra API | cloud server |
| `kiri` | client — Ruby CLI | your machine |

## Architecture

```
[ Your machine ]                    [ Cloud server ]
      │                                    │
      │  kiri show animals                 │
      │  (Ruby script, runs locally)       │
      │                                    │
      │  ── HTTP GET /animals ──────────►  │  kirid (Sinatra)
      │                                    │  reads animals.csv
      │  ◄── unicode table ─────────────  │  sends response
      │                                    │
      │  renders in terminal               │
```

## Server setup (cloud)

```bash
bundle install
mkdir data
chmod +x kirid
./kirid
```

Server starts on port **7777**. To keep it running after logout:

```bash
tmux new -s kirid
./kirid
# Ctrl+B, D to detach
```

## Client setup (your machine)

```bash
chmod +x kiri
sudo cp kiri /usr/local/bin/kiri
```

Optional gems for colors and tables:

```bash
gem install tty-table pastel   # unicode tables + colors
gem install youplot            # for kiri chart
```

Point kiri at your remote server — add to `~/.zshrc`:

```bash
export CSV_API_HOST=http://your-server-ip:7777
```

## Usage

```bash
# Create a table
kiri new   animals name,species,age

# Add rows
kiri add   animals name=Simba species=lion age=5
kiri add   animals name=Totoro species=spirit age=693

# Show all rows
kiri show  animals

# Show single row
kiri get   animals 0

# Update a row
kiri set   animals 0 age=6

# Delete a row
kiri rm    animals 1

# List all tables
kiri ls

# List columns
kiri cols  animals

# Filter by value (works on any column)
kiri grep  animals species lion

# Plot a column
kiri chart animals age bar

# Force JSON output
kiri show  animals --json
```

## Chart types

```bash
kiri chart <table> <col> bar
kiri chart <table> <col> line
kiri chart <table> <col> hist
kiri chart <table> <col> scatter
```

Requires [youplot](https://github.com/red-data-tools/YouPlot):
```bash
gem install youplot
```

## Environment variables

| Variable | Default | Side | Description |
|----------|---------|------|-------------|
| `CSV_API_HOST` | `http://localhost:7777` | client | API server URL |
| `CSV_DIR` | `./data` | server | directory for CSV files |

```bash
CSV_DIR=/var/data ./kirid
```

## API endpoints (raw HTTP)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/` | list all tables |
| GET | `/:name` | all rows |
| GET | `/:name/:id` | single row |
| POST | `/:name` | add row |
| PUT | `/:name/:id` | update row |
| DELETE | `/:name/:id` | delete row |
| POST | `/:name/create` | create new table |

Header detection: `Accept: application/json` → JSON, otherwise → unicode table.
