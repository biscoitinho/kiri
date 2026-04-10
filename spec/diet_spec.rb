require_relative 'spec_helper'

RSpec.describe 'diet module' do
  describe '#diet_sum' do
    it 'sums macros across multiple meals' do
      meals = [
        { 'kcal' => '500', 'protein_g' => '30', 'carbs_g' => '60', 'fat_g' => '10' },
        { 'kcal' => '300', 'protein_g' => '20', 'carbs_g' => '40', 'fat_g' => '8' }
      ]
      result = diet_sum(meals)
      expect(result['kcal']).to eq(800.0)
      expect(result['protein_g']).to eq(50.0)
      expect(result['carbs_g']).to eq(100.0)
      expect(result['fat_g']).to eq(18.0)
    end

    it 'handles float string values and rounds to 1 decimal' do
      meals = [
        { 'kcal' => '100.3', 'protein_g' => '10.15', 'carbs_g' => '0', 'fat_g' => '5.05' },
        { 'kcal' => '100.3', 'protein_g' => '10.15', 'carbs_g' => '0', 'fat_g' => '5.05' }
      ]
      result = diet_sum(meals)
      expect(result['kcal']).to eq(200.6)
      expect(result['protein_g']).to eq(20.3)
      expect(result['fat_g']).to eq(10.1)
    end

    it 'returns zeros for all macros on an empty meal list' do
      result = diet_sum([])
      expect(result).to eq('kcal' => 0.0, 'protein_g' => 0.0, 'carbs_g' => 0.0, 'fat_g' => 0.0)
    end

    it 'returns all four macro keys regardless of input' do
      result = diet_sum([])
      expect(result.keys).to match_array(%w[kcal protein_g carbs_g fat_g])
    end
  end

  describe '#diet_bar' do
    # With COLOR=false, c() is identity so bar chars are plaintext
    let(:width) { 20 }

    it 'returns empty string when goal is nil' do
      expect(diet_bar(500, nil)).to eq('')
    end

    it 'returns empty string when goal is zero' do
      expect(diet_bar(500, 0)).to eq('')
    end

    it 'returns empty string when goal is "0" (string from CSV)' do
      expect(diet_bar(500, '0')).to eq('')
    end

    it 'shows 0% bar when current is 0' do
      bar = diet_bar(0, 2000, width)
      expect(bar).to eq("#{'░' * 20} 0%")
    end

    it 'shows full bar at exactly 100%' do
      bar = diet_bar(2000, 2000, width)
      expect(bar).to eq("#{'█' * 20} 100%")
    end

    it 'caps at 100% when current exceeds goal' do
      bar = diet_bar(3000, 2000, width)
      expect(bar).to eq("#{'█' * 20} 100%")
    end

    it 'calculates 50% correctly (10 filled, 10 empty)' do
      bar = diet_bar(1000, 2000, width)
      expect(bar).to eq("#{'█' * 10}#{'░' * 10} 50%")
    end

    it 'rounds percentage to nearest integer' do
      # 1/3 = 33.33...% → rounds to 33
      bar = diet_bar(667, 2000, width)
      expect(bar).to end_with('33%')
    end

    it 'uses correct bar widths for different total widths' do
      bar = diet_bar(500, 1000, 10) # 50% of width=10 → 5 filled
      expect(bar).to eq("#{'█' * 5}#{'░' * 5} 50%")
    end
  end

  describe '#diet_today_meals' do
    it 'returns only meals with today\'s date' do
      today = Time.now.strftime('%Y-%m-%d')
      all_meals = [
        { 'date' => today, 'food' => 'eggs', 'kcal' => '200' },
        { 'date' => '2020-01-01', 'food' => 'old meal', 'kcal' => '100' },
        { 'date' => today, 'food' => 'toast', 'kcal' => '150' }
      ]
      allow(self).to receive(:get_json).and_return(all_meals)
      result = diet_today_meals
      expect(result.length).to eq(2)
      expect(result.map { |m| m['food'] }).to contain_exactly('eggs', 'toast')
    end

    it 'returns empty array when no meals today' do
      allow(self).to receive(:get_json).and_return([
        { 'date' => '2020-01-01', 'food' => 'old', 'kcal' => '100' }
      ])
      expect(diet_today_meals).to be_empty
    end
  end
end
