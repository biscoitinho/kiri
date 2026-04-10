require_relative 'spec_helper'

RSpec.describe 'training module' do
  describe '#training_volume' do
    it 'multiplies sets by reps' do
      row = { 'sets' => '4', 'reps' => '12', 'weight_kg' => '80' }
      expect(training_volume(row)).to eq(48)
    end

    it 'returns 0 when reps is 0' do
      row = { 'sets' => '3', 'reps' => '0', 'weight_kg' => '100' }
      expect(training_volume(row)).to eq(0)
    end

    it 'handles string integers correctly' do
      row = { 'sets' => '5', 'reps' => '5', 'weight_kg' => '100' }
      expect(training_volume(row)).to eq(25)
    end
  end

  describe '#training_for' do
    let(:sessions) do
      [
        { 'exercise' => 'squat',     'weight_kg' => '100' },
        { 'exercise' => 'bench',     'weight_kg' => '80'  },
        { 'exercise' => 'Squat',     'weight_kg' => '105' }, # different case
        { 'exercise' => 'deadlift',  'weight_kg' => '140' }
      ]
    end

    before { allow(self).to receive(:get_json).and_return(sessions) }

    it 'returns sessions for the requested exercise' do
      result = training_for('squat')
      expect(result.length).to eq(2)
    end

    it 'matches case-insensitively' do
      result = training_for('SQUAT')
      expect(result.length).to eq(2)
      expect(result.map { |r| r['weight_kg'] }).to contain_exactly('100', '105')
    end

    it 'returns empty array when exercise has no sessions' do
      expect(training_for('pullups')).to be_empty
    end
  end

  describe '#training_cutoff' do
    it 'returns a date string 30 days in the past' do
      expected = (Time.now - (30 * 86_400)).strftime('%Y-%m-%d')
      expect(training_cutoff(30)).to eq(expected)
    end

    it 'returns a date string 7 days in the past' do
      expected = (Time.now - (7 * 86_400)).strftime('%Y-%m-%d')
      expect(training_cutoff(7)).to eq(expected)
    end

    it 'returns today\'s date for 0 days' do
      expect(training_cutoff(0)).to eq(Time.now.strftime('%Y-%m-%d'))
    end
  end

  describe '#training_last' do
    let(:sessions) do
      [
        { 'date' => '2024-01-01', 'exercise' => 'squat', 'sets' => '3', 'reps' => '5', 'weight_kg' => '90',  'notes' => '' },
        { 'date' => '2024-01-08', 'exercise' => 'squat', 'sets' => '5', 'reps' => '5', 'weight_kg' => '100', 'notes' => '' },
        { 'date' => '2024-01-08', 'exercise' => 'squat', 'sets' => '3', 'reps' => '3', 'weight_kg' => '110', 'notes' => 'heavy' }
      ]
    end

    before do
      allow(self).to receive(:get_json).with('/').and_return(['training_sessions'])
      allow(self).to receive(:get_json).with('/training_sessions').and_return(sessions)
    end

    it 'renders only the most recent date\'s sessions' do
      rendered_rows = nil
      allow(self).to receive(:render_table) do |_headers, rows|
        rendered_rows = rows
      end

      training_last(['squat'])

      # Both sessions on 2024-01-08 should be shown, not the older one
      expect(rendered_rows.length).to eq(2)
      expect(rendered_rows.map { |r| r[0] }.uniq).to eq(['2024-01-08'])
    end

    it 'raises when no sessions exist for the exercise' do
      allow(self).to receive(:get_json).with('/training_sessions').and_return([])
      expect { training_last(['pullups']) }.to raise_error(/No sessions logged for 'pullups'/)
    end

    it 'raises without an exercise argument' do
      expect { training_last([]) }.to raise_error(/Usage/)
    end
  end

  describe '#training_pr' do
    let(:sessions) do
      [
        { 'date' => '2024-01-01', 'exercise' => 'squat', 'sets' => '3', 'reps' => '5', 'weight_kg' => '90' },
        { 'date' => '2024-01-08', 'exercise' => 'squat', 'sets' => '5', 'reps' => '5', 'weight_kg' => '100' },
        { 'date' => '2024-01-15', 'exercise' => 'squat', 'sets' => '2', 'reps' => '1', 'weight_kg' => '130' }
      ]
    end

    before do
      allow(self).to receive(:get_json).and_return(sessions)
      allow(self).to receive(:info)
    end

    it 'finds the heaviest weight as the PR for weight_kg metric' do
      output = []
      allow(self).to receive(:puts) { |s| output << s }
      training_pr(['squat', 'weight_kg'])
      expect(output.join).to include('130')
    end

    it 'finds the highest volume as the PR for volume metric' do
      # 3×5=15, 5×5=25, 2×1=2 → best is 5×5=25
      output = []
      allow(self).to receive(:puts) { |s| output << s }
      training_pr(['squat', 'volume'])
      expect(output.join).to include('25')
    end

    it 'raises for an unknown metric' do
      expect { training_pr(['squat', 'calories']) }.to raise_error(/Unknown metric/)
    end

    it 'raises when no sessions exist' do
      allow(self).to receive(:get_json).and_return([])
      expect { training_pr(['pullups']) }.to raise_error(/No sessions logged/)
    end
  end

  describe '#training_summary' do
    let(:sessions) do
      today = Time.now.strftime('%Y-%m-%d')
      [
        { 'date' => today, 'exercise' => 'squat',   'sets' => '5', 'reps' => '5', 'weight_kg' => '100' },
        { 'date' => today, 'exercise' => 'squat',   'sets' => '3', 'reps' => '3', 'weight_kg' => '120' },
        { 'date' => today, 'exercise' => 'bench',   'sets' => '4', 'reps' => '8', 'weight_kg' => '80'  },
        { 'date' => '2000-01-01', 'exercise' => 'bench', 'sets' => '4', 'reps' => '8', 'weight_kg' => '80' } # outside window
      ]
    end

    before do
      allow(self).to receive(:get_json).with('/').and_return(['training_sessions'])
      allow(self).to receive(:get_json).with('/training_sessions').and_return(sessions)
    end

    it 'groups sessions by exercise' do
      rendered = nil
      allow(self).to receive(:render_table) { |_h, rows| rendered = rows }
      training_summary(['30'])
      exercises = rendered.map { |r| r[0] }
      expect(exercises).to contain_exactly('squat', 'bench')
    end

    it 'counts sessions correctly per exercise within window' do
      rendered = nil
      allow(self).to receive(:render_table) { |_h, rows| rendered = rows }
      training_summary(['30'])
      squat_row = rendered.find { |r| r[0] == 'squat' }
      bench_row = rendered.find { |r| r[0] == 'bench' }
      expect(squat_row[1]).to eq('2') # 2 squat sessions today
      expect(bench_row[1]).to eq('1') # only 1 bench in last 30 days (old one excluded)
    end

    it 'reports max weight for each exercise' do
      rendered = nil
      allow(self).to receive(:render_table) { |_h, rows| rendered = rows }
      training_summary(['30'])
      squat_row = rendered.find { |r| r[0] == 'squat' }
      expect(squat_row[2]).to eq('120.0') # max of 100, 120
    end

    it 'raises when no sessions in the window' do
      allow(self).to receive(:get_json).with('/training_sessions').and_return([
        { 'date' => '2000-01-01', 'exercise' => 'squat', 'sets' => '3', 'reps' => '5', 'weight_kg' => '100' }
      ])
      expect { training_summary(['7']) }.to raise_error(/No sessions in the last 7 days/)
    end
  end

  describe '#training_log' do
    before do
      allow(self).to receive(:get_json).with('/').and_return(['training_sessions'])
    end

    it 'auto-sets date to today' do
      posted = nil
      allow(self).to receive(:http_req) { |_m, _p, params| posted = params; 'Created row #0' }

      training_log(['exercise=pushups', 'sets=3', 'reps=20', 'weight_kg=0'])
      expect(posted['date']).to eq(Time.now.strftime('%Y-%m-%d'))
    end

    it 'raises when no args are given' do
      expect { training_log([]) }.to raise_error(/Usage/)
    end

    it 'raises when exercise is missing' do
      expect { training_log(['sets=3', 'reps=10', 'weight_kg=80']) }.to raise_error(/exercise is required/)
    end

    it 'defaults notes to empty string when not provided' do
      posted = nil
      allow(self).to receive(:http_req) { |_m, _p, params| posted = params; 'Created row #0' }

      training_log(['exercise=squat', 'sets=5', 'reps=5', 'weight_kg=100'])
      expect(posted['notes']).to eq('')
    end
  end
end
