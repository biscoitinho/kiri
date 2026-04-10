# ── training module ────────────────────────────────────────────────────────────

TRAINING_TABLE = 'training_sessions'.freeze
TRAINING_COLS  = 'date,exercise,sets,reps,weight_kg,notes'.freeze

def training_ensure_tables
  tables = get_json('/')
  http_req(:post, "/#{TRAINING_TABLE}/create", { 'headers' => TRAINING_COLS }) unless tables.include?(TRAINING_TABLE)
end

def training_all
  get_json("/#{TRAINING_TABLE}")
end

def training_for(exercise)
  training_all.select { |r| r['exercise'].to_s.downcase == exercise.downcase }
end

def training_volume(row)
  row['sets'].to_i * row['reps'].to_i
end

def training_render(rows)
  return puts(c('  ( no sessions )', :dim)) if rows.empty?

  cols = %w[date exercise sets reps weight_kg notes]
  render_table(cols, rows.map { |r| r.values_at(*cols) })
end

def training_cutoff(days)
  (Time.now - (days * 86_400)).strftime('%Y-%m-%d')
end

# ── subcommand handlers ────────────────────────────────────────────────────────

def training_log(args)
  err 'Usage: kiri training log exercise=<name> sets=X reps=X weight_kg=X [notes=...]' if args.empty?
  pairs = parse_pairs(args)
  err 'exercise is required' unless pairs['exercise']
  pairs['date'] = Time.now.strftime('%Y-%m-%d')
  pairs['notes'] ||= ''
  ok http_req(:post, "/#{TRAINING_TABLE}", pairs).strip
  puts "  #{c(pairs['exercise'], :cyan, :bold)}  #{pairs['sets']}×#{pairs['reps']} @ #{pairs['weight_kg']}kg"
end

def training_today
  today = Time.now.strftime('%Y-%m-%d')
  rows  = training_all.select { |r| r['date'] == today }
  info "#{today} — training sessions (#{rows.length})"
  training_render(rows)
end

def training_ls(args)
  exercise = args.shift
  rows  = exercise ? training_for(exercise) : training_all
  label = exercise ? "#{exercise} sessions" : 'all sessions'
  info "#{label} (#{rows.length})"
  training_render(rows)
end

def training_history(args)
  exercise = args.shift
  days     = (args.shift || '30').to_i
  err 'Usage: kiri training history <exercise> [days]' unless exercise
  rows = training_for(exercise).select { |r| r['date'] >= training_cutoff(days) }
  info "#{exercise} — last #{days} days (#{rows.length} sessions)"
  training_render(rows)
end

def training_last(args)
  exercise = args.shift
  err 'Usage: kiri training last <exercise>' unless exercise
  rows = training_for(exercise).sort_by { |r| r['date'] }
  err "No sessions logged for '#{exercise}'" if rows.empty?
  last_date = rows.last['date']
  info "#{exercise} — last session (#{last_date})"
  training_render(rows.select { |r| r['date'] == last_date })
end

def training_pr(args)
  exercise = args.shift
  metric   = args.shift || 'weight_kg'
  err 'Usage: kiri training pr <exercise> [weight_kg|volume]' unless exercise
  err "Unknown metric '#{metric}'. Use: weight_kg, volume" unless %w[weight_kg volume].include?(metric)
  rows = training_for(exercise)
  err "No sessions logged for '#{exercise}'" if rows.empty?

  if metric == 'volume'
    best     = rows.max_by { |r| training_volume(r) }
    best_val = training_volume(best)
    info "#{exercise} — personal record (volume: sets×reps)"
    puts "  #{c('PR', :yellow, :bold)}  #{best_val} reps total  on #{best['date']}  (#{best['sets']}×#{best['reps']})"
  else
    best = rows.max_by { |r| r['weight_kg'].to_f }
    info "#{exercise} — personal record (weight)"
    puts "  #{c('PR', :yellow, :bold)}  #{best['weight_kg']} kg  on #{best['date']}  (#{best['sets']}×#{best['reps']})"
  end
end

def training_summary(args)
  days = (args.shift || '30').to_i
  rows = training_all.select { |r| r['date'] >= training_cutoff(days) }
  err "No sessions in the last #{days} days" if rows.empty?

  info "training summary — last #{days} days"
  summary_rows = rows.group_by { |r| r['exercise'] }.map do |ex, sessions|
    [ex,
     sessions.length.to_s,
     sessions.map { |r| r['weight_kg'].to_f }.max.to_s,
     sessions.map { |r| training_volume(r) }.max.to_s,
     sessions.map { |r| r['date'] }.max]
  end
  summary_rows.sort_by! { |r| r[4] }
  summary_rows.reverse!
  render_table(%w[exercise sessions max_weight_kg max_volume last_date], summary_rows)
end

def training_chart(args)
  err 'youplot not installed — run: gem install youplot' unless system('which uplot > /dev/null 2>&1')
  exercise = args.shift
  metric   = args.shift || 'weight_kg'
  days     = (args.shift || '30').to_i
  err 'Usage: kiri training chart <exercise> [weight_kg|volume] [days]' unless exercise
  err "Unknown metric '#{metric}'. Use: weight_kg, volume" unless %w[weight_kg volume].include?(metric)

  rows = training_for(exercise).select { |r| r['date'] >= training_cutoff(days) }
  err "No sessions for '#{exercise}' in the last #{days} days" if rows.empty?

  if metric == 'weight_kg' && rows.all? { |r| r['weight_kg'].to_f.zero? }
    info 'All weight_kg values are 0 — switching to volume (sets×reps)'
    metric = 'volume'
  end

  by_date = rows.group_by { |r| r['date'] }
  dates   = by_date.keys.sort.last(days)
  points  = if metric == 'volume'
              dates.map { |d| [d, by_date[d].sum { |r| training_volume(r) }] }
            else
              dates.map { |d| [d, by_date[d].map { |r| r['weight_kg'].to_f }.max.round(1)] }
            end

  info "#{exercise} / #{metric} — last #{days} days"
  IO.popen(['uplot', 'bar', '-H', '-t', "#{exercise} — #{metric}"], 'w') do |io|
    io.puts "date\t#{metric}"
    points.each { |d, v| io.puts "#{d}\t#{v}" }
  end
end

def training_usage
  puts <<~USAGE
    #{c('kiri training', :cyan, :bold)} — training log

      kiri training init                                    create training table
      kiri training log exercise=<name> sets=X reps=X weight_kg=X [notes=...]
                                                            log a session
      kiri training today                                   today's sessions
      kiri training ls [exercise]                           all sessions (optional filter)
      kiri training history <exercise> [days]               sessions in last N days (default 30)
      kiri training last <exercise>                         most recent session for exercise
      kiri training pr <exercise> [weight_kg|volume]        personal record
      kiri training summary [days]                          per-exercise summary (default 30 days)
      kiri training chart <exercise> [metric] [days]        progression chart

    Metrics: weight_kg (default), volume (sets×reps)
  USAGE
end

# ── dispatcher ─────────────────────────────────────────────────────────────────

def cmd_training(subcmd, args)
  training_ensure_tables

  case subcmd
  when 'init'        then ok "Training table ready: #{TRAINING_TABLE}"
  when 'log'         then training_log(args)
  when 'today'       then training_today
  when 'ls', 'list'  then training_ls(args)
  when 'history'     then training_history(args)
  when 'last'        then training_last(args)
  when 'pr'          then training_pr(args)
  when 'summary'     then training_summary(args)
  when 'chart'       then training_chart(args)
  else                    training_usage
  end
end
