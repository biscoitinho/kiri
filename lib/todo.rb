# ── todo module ────────────────────────────────────────────────────────────────

TODOS_TABLE = 'todos'
TODOS_COLS  = 'id,title,status,created_at,done_at,notes'

def todo_ensure_tables
  tables = get_json('/')
  http_req(:post, "/#{TODOS_TABLE}/create", { 'headers' => TODOS_COLS }) unless tables.include?(TODOS_TABLE)
end

# Find a row by stable id column value. Returns the full row hash (including _id).
def todo_find(id_val)
  data = get_json("/#{TODOS_TABLE}")
  match = data.find { |r| r['id'] == id_val.to_s }
  err "No task with id #{id_val}" unless match
  match
end

def todo_status_color(status)
  case status
  when 'open'      then c(status, :green)
  when 'done'      then c(status, :dim)
  when 'cancelled' then c(status, :yellow)
  else status
  end
end

def todo_render(rows)
  return puts(c('  ( no tasks )', :dim)) if rows.empty?
  cols = %w[id title status created_at done_at notes]
  colored_rows = rows.map do |r|
    [r['id'], r['title'], todo_status_color(r['status']),
     r['created_at'], r['done_at'], r['notes']]
  end
  render_table(cols, colored_rows)
end

def cmd_todo(subcmd, args)
  todo_ensure_tables

  # default to 'ls' when no subcommand given
  subcmd ||= 'ls'

  case subcmd

  when 'init'
    ok "Todo table ready: #{TODOS_TABLE}"

  when 'add'
    title = args.shift
    err "Usage: kiri todo add <title> [notes=...]" unless title
    pairs = parse_pairs(args)
    data  = get_json("/#{TODOS_TABLE}")
    new_id = data.empty? ? 0 : data.map { |r| r['id'].to_i }.max + 1
    row = {
      'id'         => new_id.to_s,
      'title'      => title,
      'status'     => 'open',
      'created_at' => Time.now.strftime('%Y-%m-%d'),
      'done_at'    => '',
      'notes'      => pairs['notes'] || ''
    }
    ok http_req(:post, "/#{TODOS_TABLE}", row).strip
    puts "  #{c('#' + new_id.to_s, :cyan, :bold)}  #{title}"

  when 'ls', 'list'
    data = get_json("/#{TODOS_TABLE}")
    open = data.select { |r| r['status'] == 'open' }
    info "open tasks (#{open.length})"
    todo_render(open)

  when 'log'
    data = get_json("/#{TODOS_TABLE}")
    done = data.select { |r| %w[done cancelled].include?(r['status']) }
    info "completed / cancelled (#{done.length})"
    todo_render(done)

  when 'all'
    data = get_json("/#{TODOS_TABLE}")
    info "all tasks (#{data.length})"
    todo_render(data)

  when 'show'
    id_val = args.shift
    err "Usage: kiri todo show <id>" unless id_val
    row = todo_find(id_val)
    info "task ##{row['id']}"
    row.reject { |k, _| k == '_id' }.each do |k, v|
      puts "  #{c(k.ljust(12), :cyan)}  #{v}"
    end

  when 'done'
    id_val = args.shift
    err "Usage: kiri todo done <id>" unless id_val
    row = todo_find(id_val)
    if row['status'] == 'done'
      info "Already done"
    else
      http_req(:put, "/#{TODOS_TABLE}/#{row['_id']}", 'status' => 'done', 'done_at' => Time.now.strftime('%Y-%m-%d'))
      ok "Task ##{row['id']} marked done: #{row['title']}"
    end

  when 'cancel'
    id_val = args.shift
    err "Usage: kiri todo cancel <id>" unless id_val
    row = todo_find(id_val)
    if row['status'] == 'cancelled'
      info "Already cancelled"
    else
      http_req(:put, "/#{TODOS_TABLE}/#{row['_id']}", 'status' => 'cancelled', 'done_at' => Time.now.strftime('%Y-%m-%d'))
      ok "Task ##{row['id']} cancelled: #{row['title']}"
    end

  when 'rm', 'delete'
    id_val = args.shift
    err "Usage: kiri todo rm <id>" unless id_val
    row = todo_find(id_val)
    http_req(:delete, "/#{TODOS_TABLE}/#{row['_id']}")
    ok "Deleted task ##{row['id']}: #{row['title']}"

  when 'edit'
    id_val = args.shift
    err "Usage: kiri todo edit <id> title=... notes=..." unless id_val && !args.empty?
    row   = todo_find(id_val)
    pairs = parse_pairs(args)
    http_req(:put, "/#{TODOS_TABLE}/#{row['_id']}", pairs)
    ok "Updated task ##{row['id']}"

  else
    puts <<~USAGE
      #{c('kiri todo', :cyan, :bold)} — task tracker

        kiri todo init              create todos table
        kiri todo add <title>       add a new task
        kiri todo add <title> notes=<text>
        kiri todo ls                list open tasks (default)
        kiri todo log               list done / cancelled tasks
        kiri todo all               list all tasks
        kiri todo show <id>         show task detail
        kiri todo done <id>         mark task done
        kiri todo cancel <id>       mark task cancelled
        kiri todo edit <id> title=… notes=…   update fields
        kiri todo rm <id>           permanently delete task
    USAGE
  end
end
