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
# Keep the full kiri/ directory intact — modules live in lib/
# Symlink the binary so lib/ is always found via File.realpath:
ln -s /path/to/kiri/kiri ~/bin/kiri
# or add the directory to PATH:
export PATH="/path/to/kiri:$PATH"
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

## Modules

Kiri ships with three built-in modules, each in its own file under `lib/`. Every module follows the same pattern: run `init` once to create its tables, then use subcommands freely.

### Diet tracker (`lib/diet.rb`)

Logs meals, tracks daily macros against goals, plots trends.

```bash
kiri diet init

# Set daily goals
kiri diet goal set kcal=2200 protein_g=150 carbs_g=250 fat_g=70

# Log a meal (date auto-set to today)
kiri diet log "kurczak z ryżem" kcal=650 protein_g=45 carbs_g=80 fat_g=12
kiri diet log "jajecznica" kcal=280 protein_g=21 carbs_g=2 fat_g=20

# Today's summary — meals + progress bars vs goals
kiri diet today

# Show current goals
kiri diet goal show

# Charts (requires youplot)
kiri diet chart kcal 7       # calories, last 7 days
kiri diet chart protein_g 14 # protein, last 14 days
```

Available macros: `kcal`, `protein_g`, `carbs_g`, `fat_g`.  
Tables: `meals`, `diet_goals`.

---

### Todo (`lib/todo.rb`)

Git-inspired task tracker. Tasks get stable integer IDs that don't shift when other tasks are deleted.

```bash
kiri todo init

# Add tasks
kiri todo add "write unit tests"
kiri todo add "update readme" notes="see issue #3"

# List open tasks (also the default: kiri todo)
kiri todo ls

# Close tasks
kiri todo done 0
kiri todo cancel 1

# Browse completed/cancelled tasks
kiri todo log

# All tasks regardless of status
kiri todo all

# Detail, edit, delete
kiri todo show 0
kiri todo edit 0 notes="updated context"
kiri todo rm 0
```

Statuses: `open` (green), `done` (dim), `cancelled` (yellow).  
Table: `todos`.

---

### Training log (`lib/training.rb`)

Logs workout sessions, tracks progression, surfaces personal records.

```bash
kiri training init

# Log sessions (date auto-set to today)
kiri training log exercise=squat sets=5 reps=5 weight_kg=100
kiri training log exercise=pushups sets=3 reps=20 weight_kg=0 notes="easy"

# Today's sessions
kiri training today

# Browse history
kiri training ls                    # all sessions
kiri training ls squat              # filter by exercise
kiri training history squat 30      # last 30 days for one exercise

# Last session for an exercise (great for "what weight did I use?")
kiri training last squat

# Personal records
kiri training pr squat weight_kg    # heaviest weight
kiri training pr pushups volume     # highest sets×reps

# Per-exercise dashboard (sessions, max weight, max volume, last date)
kiri training summary 30

# Progression chart (requires youplot)
kiri training chart squat weight_kg 30   # weight over last 30 days
kiri training chart pushups volume 14    # volume over last 14 days
```

Metrics: `weight_kg` (default), `volume` (sets×reps). Chart auto-switches to volume if all weights are 0.  
Table: `training_sessions`.

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
