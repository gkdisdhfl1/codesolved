<?php
    // includes/tier_helper.php

    /**
     * 사용자의 레이팅과 랭킹 순위를 바탕으로 상세 티어 데이터를 반환
     */
    function get_tier_info($rating, $rank = null) {
        $tier = [
            'name' => 'Unranked',
            'short_name' => 'Unranked',
            'rank_num' => '',
            'class' => 'tier-unranked',
            'color' => '#8a8f9d',
            'min_rating' => 0,
            'max_rating' => 99,
            'progress' => 0,
        ];

        if ($rating < 100) {
            $tier['progress'] = $rating;
            return $tier;
        }

        // 최상위 티어 판별 (2500점 이상)
        if ($rating >= 2500) {
            $tier['min_rating'] = 2500;
            $tier['max_rating'] = 9999;
            $tier['progress'] = 100;
            
            if ($rank === 1) {
                $tier['name'] = 'Challenger';
                $tier['short_name'] = 'Challenger';
                $tier['class'] = 'tier-challenger';
                $tier['color'] = '#00d2ff';
            } elseif ($rank >= 2 && $rank <= 5) {
                $tier['name'] = 'Grandmaster';
                $tier['short_name'] = 'GrandMaster';
                $tier['class'] = 'tier-grandmaster';
                $tier['color'] = '#ff3366';
            } else {
                $tier['name'] = 'Master';
                $tier['short_name'] = 'Master';
                $tier['class'] = 'tier-master';
                $tier['color'] = '#d000ff';
            }
            return $tier;
        }

        // 일반 티어 설정 구간 정의 (400점 단위)
        $tiers = [
            ['name' => 'Iron',       'class' => 'tier-iron',     'color' => '#5c6370', 'min' => 100],
            ['name' => 'Bronze',     'class' => 'tier-bronze',   'color' => '#a0522d', 'min' => 100],
            ['name' => 'Silver',     'class' => 'tier-silver',   'color' => '#a8b4c4', 'min' => 100],
            ['name' => 'Gold',       'class' => 'tier-gold',     'color' => '#e5b83b', 'min' => 100],
            ['name' => 'Platinum',   'class' => 'tier-platinum', 'color' => '#2cb396', 'min' => 100],
            ['name' => 'Diamond',    'class' => 'tier-diamond',  'color' => '#57a3e4', 'min' => 100],
        ];

        $currentTierIndex = 0;
        foreach ($tiers as $i => $t) {
            if ($rating >= $t['min']) {
                $currentTierIndex = $i;
            } else {
                break;
            }
        }

        $cTier = $tiers[$currentTierIndex];
        $tier['short_name'] = $cTier['name'];
        $tier['class'] = $cTier['class'];
        $tier['color'] = $cTier['color'];
        $tier['min_rating'] = $cTier['min'];
        $tier['max_rating'] = $cTier['min'] + 399;

        // 단계 (IV ~ I) 분배
        $relativeRating = $rating - $cTier['min'];
        $step = (int)($relativeRating / 100);

        $romanNumerals = ['IV', 'III', 'II', 'I'];
        $tier['rank_num'] = $romanNumerals[$step];
        $tier['name'] = $cTier['name'] . ' ' . $tier['rank_num'];
        $tier['progress'] = $relativeRating % 100;

        return $tier;
    }
?>