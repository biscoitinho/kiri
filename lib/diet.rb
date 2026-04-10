# ── diet module ────────────────────────────────────────────────────────────────

MEALS_TABLE = 'meals'.freeze
GOALS_TABLE = 'diet_goals'.freeze
MEALS_COLS  = 'date,food,kcal,protein_g,carbs_g,fat_g'.freeze
GOALS_COLS  = 'kcal,protein_g,carbs_g,fat_g'.freeze
MACROS      = %w[kcal protein_g carbs_g fat_g].freeze

def diet_ensure_tables
  tables = get_json('/')
  http_req(:post, "/#{MEALS_TABLE}/create", { 'headers' => MEALS_COLS }) unless tables.include?(MEALS_TABLE)
  http_req(:post, "/#{GOALS_TABLE}/create", { 'headers' => GOALS_COLS }) unless tables.include?(GOALS_TABLE)
end

def diet_get_goals
  get_json("/#{GOALS_TABLE}").first
end

def diet_today_meals
  today = Time.now.strftime('%Y-%m-%d')
  get_json("/#{MEALS_TABLE}").select { |r| r['date'] == today }
end

def diet_sum(meals)
  MACROS.to_h do |col|
    [col, meals.sum { |r| r[col].to_f }.round(1)]
  end
end

def diet_bar(current, goal, width = 20)
  return '' unless goal&.to_f&.positive?

  pct    = [current.to_f / goal, 1.0].min
  filled = (pct * width).round
  bar    = c('█' * filled, if pct < 0.85
                             :green
                           else
                             pct < 1.0 ? :yellow : :red
                           end)
  "#{bar}#{c('░' * (width - filled), :dim)} #{(pct * 100).round}%"
end

def cmd_diet(subcmd, args)
  diet_ensure_tables

  case subcmd

  when 'init'
    ok "Diet tables ready: #{MEALS_TABLE}, #{GOALS_TABLE}"

  when 'log'
    food = args.shift
    err 'Usage: kiri diet log <food> kcal=X protein_g=X carbs_g=X fat_g=X' unless food
    pairs         = parse_pairs(args)
    pairs['date'] = Time.now.strftime('%Y-%m-%d')
    pairs['food'] = food
    ok http_req(:post, "/#{MEALS_TABLE}", pairs).strip

  when 'today'
    today  = Time.now.strftime('%Y-%m-%d')
    meals  = diet_today_meals
    totals = diet_sum(meals)
    goals  = diet_get_goals

    info "#{today} — meals"
    if meals.empty?
      puts c('  ( no meals logged today )', :dim)
    else
      cols = %w[food kcal protein_g carbs_g fat_g]
      render_table(cols, meals.map { |m| m.values_at(*cols) })
    end

    puts
    info 'daily totals'
    MACROS.each do |col|
      cur      = totals[col]
      goal     = goals ? goals[col].to_f : nil
      goal_str = goal ? "/ #{goal}" : '/ --'
      puts "  #{c(col.ljust(10), :cyan)}  #{cur.to_s.rjust(7)} #{goal_str.ljust(10)}  #{diet_bar(cur, goal)}"
    end

  when 'goal'
    sub2 = args.shift
    case sub2
    when 'set'
      err 'Usage: kiri diet goal set kcal=X protein_g=X carbs_g=X fat_g=X' if args.empty?
      pairs = parse_pairs(args)
      data  = get_json("/#{GOALS_TABLE}")
      if data.empty?
        ok http_req(:post, "/#{GOALS_TABLE}", pairs).strip
      else
        ok http_req(:put, "/#{GOALS_TABLE}/0", pairs).strip
      end
    when 'show', nil
      goals = diet_get_goals
      err 'No goals set — run: kiri diet goal set kcal=2000 protein_g=150 carbs_g=200 fat_g=70' unless goals
      info 'daily goals'
      MACROS.each { |col| puts "  #{c(col.ljust(10), :cyan)}  #{goals[col]}" }
    else
      err 'Usage: kiri diet goal [set|show]'
    end

  when 'chart'
    err 'youplot not installed — run: gem install youplot' unless system('which uplot > /dev/null 2>&1')
    macro = args.shift || 'kcal'
    days  = (args.shift || '7').to_i
    err "Unknown macro '#{macro}'. Use: #{MACROS.join(', ')}" unless MACROS.include?(macro)

    data = get_json("/#{MEALS_TABLE}")
    err 'No meals logged yet' if data.empty?

    by_date = data.group_by { |r| r['date'] }
    dates   = by_date.keys.sort.last(days)
    points  = dates.map { |d| [d, by_date[d].sum { |r| r[macro].to_f }.round(1)] }

    info "#{macro} — last #{days} days"
    IO.popen(['uplot', 'bar', '-H', '-t', "#{macro} / day"], 'w') do |io|
      io.puts "date\t#{macro}"
      points.each { |d, v| io.puts "#{d}\t#{v}" }
    end

  else
    puts <<~USAGE
      #{c('kiri diet', :cyan, :bold)} — dietary tracker

        kiri diet init                              create meals & goals tables
        kiri diet log <food> kcal=X protein_g=X …  log a meal (today's date auto-set)
        kiri diet today                             show today's meals + totals vs goals
        kiri diet goal set kcal=X protein_g=X …    set daily macro goals
        kiri diet goal show                         show current goals
        kiri diet chart [macro] [days]              bar chart (default: kcal, 7 days)

      Macros: #{MACROS.join(', ')}
    USAGE
  end
end
