require 'json'

# ── Stub the global helpers that lib/*.rb depend on ────────────────────────────
# In tests, COLOR is off so c() is identity, and err() raises instead of exit.

HOST  = 'http://localhost:7777' unless defined?(HOST)
COLOR = false                    unless defined?(COLOR)
P     = nil                      unless defined?(P)

def c(text, *_styles)
  text.to_s
end

def ok(msg)   = nil # swallow
def info(msg) = nil # swallow

# err() raises so tests can assert on it
def err(msg)
  raise msg
end

def render_table(_headers, _rows) = nil # captured separately when needed

def parse_pairs(pairs)
  pairs.each_with_object({}) do |pair, h|
    k, v = pair.split('=', 2)
    raise "Invalid pair '#{pair}'" unless k && v

    h[k] = v
  end
end

def strip_id(data)
  return [[], []] if data.empty?

  headers = data.first.keys.reject { |k| k == '_id' }
  rows    = data.map { |r| r.values_at(*headers) }
  [headers, rows]
end

def http_req(_method, _path, _params = nil)
  raise 'http_req called without stub'
end

def get_json(_path)
  raise 'get_json called without stub'
end

# Load modules under test
require_relative '../lib/diet'
require_relative '../lib/todo'
require_relative '../lib/training'

RSpec.configure do |config|
  config.expect_with :rspec do |c|
    c.syntax = :expect
  end
end
