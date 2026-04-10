# ── training module ────────────────────────────────────────────────────────────

TRAINING_TABLE = 'training_sessions'
TRAINING_COLS  = 'date,exercise,sets,reps,weight_kg,notes'

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

# Render a list of session rows
def training_render(rows)
  return puts(c('  ( no sessions )', :dim)) if rows.empty?
  cols = %w[date exercise sets reps weight_kg notes]
  render_table(cols, rows.map { |r| r.values_at(*cols) })
end

def cmd_training(subcmd, args)
  training_ensure_tables

  case subcmd

  when 'init'
    ok "Training table ready: #{TRAINING_TABLE}"

  when 'log'
    err "Usage: kiri training log exercise=<name> sets=X reps=X weight_kg=X [notes=...]" if args.empty?
    pairs = parse_pairs(args)
    err "exercise is required" unless pairs['exercise']
    pairs['date']  = Time.now.strftime('%Y-%m-%d')
    pairs['notes'] ||= ''
    ok http_req(:post, "/#{TRAINING_TABLE}", pairs).strip
    puts "  #{c(pairs['exercise'], :cyan, :bold)}  #{pairs['sets']}×#{pairs['reps']} @ #{pairs['weight_kg']}kg"

  when 'today'
    today = Time.now.strftime('%Y-%m-%d')
    rows  = training_all.select { |r| r['date'] == today }
    info "#{today} — training sessions (#{rows.length})"
    training_render(rows)

  when 'ls', 'list'
    exercise = args.shift
    rows = exercise ? training_for(exercise) : training_all
    label = exercise ? "#{exercise} sessions" : "all sessions"
    info "#{label} (#{rows.length})"
    training_render(rows)

  when 'history'
    exercise = args.shift
    days     = (args.shift || '30').to_i
    err "Usage: kiri training history <exercise> [days]" unless exercise
    cutoff = (Time.now - days * 86400).strftime('%Y-%m-%d')
    rows   = training_for(exercise).select { |r| r['date'] >= cutoff }
    info "#{exercise} — last #{days} days (#{rows.length} sessions)"
    training_render(rows)

  when 'last'
    exercise = args.shift
    err "Usage: kiri training last <exercise>" unless exercise
    rows = training_for(exercise).sort_by { |r| r['date'] }
    err "No sessions logged for '#{exercise}'" if rows.empty?
    last_date = rows.last['date']
    last_rows = rows.select { |r| r['date'] == last_date }
    info "#{exercise} — last session (#{last_date})"
    training_render(last_rows)

  when 'pr'
    exercise = args.shift
    metric   = args.shift || 'weight_kg'
    err "Usage: kiri training pr <exercise> [weight_kg|volume]" unless exercise
    err "Unknown metric '#{metric}'. Use: weight_kg, volume" unless %w[weight_kg volume].include?(metric)
    rows = training_for(exercise)
    err "No sessions logged for '#{exercise}'" if rows.empty?

    if metric == 'volume'
      best_row  = rows.max_by { |r| training_volume(r) }
      best_val  = training_volume(best_row)
      info "#{exercise} — personal record (volume: sets×reps)"
      puts "  #{c('PR', :yellow, :bold)}  #{best_val} reps total  on #{best_row['date']}  (#{best_row['sets']}×#{best_row['reps']})"
    else
      best_row = rows.max_by { |r| r['weight_kg'].to_f }
      info "#{exercise} — personal record (weight)"
      puts "  #{c('PR', :yellow, :bold)}  #{best_row['weight_kg']} kg  on #{best_row['date']}  (#{best_row['sets']}×#{best_row['reps']})"
    end

  when 'summary'
    days   = (args.shift || '30').to_i
    cutoff = (Time.now - days * 86400).strftime('%Y-%m-%d')
    rows   = training_all.select { |r| r['date'] >= cutoff }
    err "No sessions in the last #{days} days" if rows.empty?

    by_exercise = rows.group_by { |r| r['exercise'] }
    info "training summary — last #{days} days"
    summary_rows = by_exercise.map do |ex, sessions|
      max_weight = sessions.map { |r| r['weight_kg'].to_f }.max
      max_volume = sessions.map { |r| training_volume(r) }.max
      last_date  = sessions.map { |r| r['date'] }.max
      [ex, sessions.length.to_s, max_weight.to_s, max_volume.to_s, last_date]
    end.sort_by { |r| r[4] }.reverse  # sort by last_date desc
    render_table(%w[exercise sessions max_weight_kg max_volume last_date], summary_rows)

  when 'chart'
    err "youplot not installed — run: gem install youplot" unless system('which uplot > /dev/null 2>&1')
    exercise = args.shift
    metric   = args.shift || 'weight_kg'
    days     = (args.shift || '30').to_i
    err "Usage: kiri training chart <exercise> [weight_kg|volume] [days]" unless exercise
    err "Unknown metric '#{metric}'. Use: weight_kg, volume" unless %w[weight_kg volume].include?(metric)

    cutoff = (Time.now - days * 86400).strftime('%Y-%m-%d')
    rows   = training_for(exercise).select { |r| r['date'] >= cutoff }
    err "No sessions for '#{exercise}' in the last #{days} days" if rows.empty?

    # If metric is weight_kg but all values are 0, auto-switch to volume
    if metric == 'weight_kg' && rows.all? { |r| r['weight_kg'].to_f == 0 }
      info "All weight_kg values are 0 — switching to volume (sets×reps)"
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

  else
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
end
