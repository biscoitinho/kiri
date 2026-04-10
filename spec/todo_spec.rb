require_relative 'spec_helper'

RSpec.describe 'todo module' do
  describe '#todo_find' do
    let(:tasks) do
      [
        { '_id' => 0, 'id' => '0', 'title' => 'first task',  'status' => 'open' },
        { '_id' => 1, 'id' => '1', 'title' => 'second task', 'status' => 'done' },
        { '_id' => 2, 'id' => '2', 'title' => 'third task',  'status' => 'open' }
      ]
    end

    before { allow(self).to receive(:get_json).and_return(tasks) }

    it 'finds a task by its stable id' do
      result = todo_find('1')
      expect(result['title']).to eq('second task')
    end

    it 'returns the _id (positional index) alongside data' do
      result = todo_find('2')
      expect(result['_id']).to eq(2)
    end

    it 'raises when no task matches the id' do
      expect { todo_find('99') }.to raise_error(/No task with id 99/)
    end

    it 'matches by string id even when given an integer' do
      result = todo_find(0)
      expect(result['title']).to eq('first task')
    end
  end

  describe '#todo_status_color' do
    # COLOR is false in tests, so c() returns the text unchanged — we test
    # that the right label is passed, not ANSI codes.
    it 'returns "open" for open status' do
      expect(todo_status_color('open')).to eq('open')
    end

    it 'returns "done" for done status' do
      expect(todo_status_color('done')).to eq('done')
    end

    it 'returns "cancelled" for cancelled status' do
      expect(todo_status_color('cancelled')).to eq('cancelled')
    end

    it 'returns unknown status unchanged' do
      expect(todo_status_color('whatever')).to eq('whatever')
    end
  end

  describe 'ID assignment in cmd_todo add' do
    # Test the ID-generation logic in isolation by calling cmd_todo('add', ...)
    # and verifying what gets POSTed to the API.

    it 'assigns id 0 to the first task in an empty table' do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return([])

      posted = nil
      allow(self).to receive(:http_req) do |_method, _path, params|
        posted = params
        'Created row #0'
      end

      cmd_todo('add', ['my first task'])
      expect(posted['id']).to eq('0')
    end

    it 'assigns max_existing_id + 1 when tasks already exist' do
      existing = [
        { 'id' => '0', 'title' => 'a', 'status' => 'open' },
        { 'id' => '3', 'title' => 'b', 'status' => 'done' }  # gap in IDs is fine
      ]
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return(existing)

      posted = nil
      allow(self).to receive(:http_req) do |_method, _path, params|
        posted = params
        'Created row #2'
      end

      cmd_todo('add', ['new task'])
      expect(posted['id']).to eq('4')  # max(0,3) + 1 = 4
    end

    it 'sets status to open and created_at to today' do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return([])

      posted = nil
      allow(self).to receive(:http_req) { |_m, _p, params| posted = params; 'Created row #0' }

      cmd_todo('add', ['task title'])
      expect(posted['status']).to eq('open')
      expect(posted['created_at']).to eq(Time.now.strftime('%Y-%m-%d'))
    end

    it 'captures notes from key=value args' do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return([])

      posted = nil
      allow(self).to receive(:http_req) { |_m, _p, params| posted = params; 'Created row #0' }

      cmd_todo('add', ['my task', 'notes=see issue #3'])
      expect(posted['notes']).to eq('see issue #3')
    end
  end

  describe 'cmd_todo done' do
    let(:tasks) do
      [{ '_id' => 0, 'id' => '0', 'title' => 'write tests', 'status' => 'open' }]
    end

    before do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return(tasks)
    end

    it 'sends a PUT with status=done and done_at=today' do
      expect(self).to receive(:http_req).with(
        :put, '/todos/0',
        hash_including('status' => 'done', 'done_at' => Time.now.strftime('%Y-%m-%d'))
      )
      cmd_todo('done', ['0'])
    end

    it 'does not send a PUT when task is already done' do
      tasks[0]['status'] = 'done'
      expect(self).not_to receive(:http_req).with(:put, anything, anything)
      cmd_todo('done', ['0'])
    end

    it 'raises when task id is not found' do
      expect { cmd_todo('done', ['99']) }.to raise_error(/No task with id 99/)
    end
  end

  describe 'cmd_todo cancel' do
    let(:tasks) { [{ '_id' => 0, 'id' => '0', 'title' => 'old idea', 'status' => 'open' }] }

    before do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return(tasks)
    end

    it 'sends PUT with status=cancelled' do
      expect(self).to receive(:http_req).with(
        :put, '/todos/0',
        hash_including('status' => 'cancelled')
      )
      cmd_todo('cancel', ['0'])
    end

    it 'skips the PUT when task is already cancelled' do
      tasks[0]['status'] = 'cancelled'
      expect(self).not_to receive(:http_req).with(:put, anything, anything)
      cmd_todo('cancel', ['0'])
    end
  end

  describe 'cmd_todo rm' do
    let(:tasks) { [{ '_id' => 0, 'id' => '0', 'title' => 'stale task', 'status' => 'open' }] }

    before do
      allow(self).to receive(:get_json).with('/').and_return(['todos'])
      allow(self).to receive(:get_json).with('/todos').and_return(tasks)
    end

    it 'sends DELETE to the positional _id, not the stable id' do
      expect(self).to receive(:http_req).with(:delete, '/todos/0')
      cmd_todo('rm', ['0'])
    end
  end
end
