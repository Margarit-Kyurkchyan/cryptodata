<?php

namespace App\Services;

use DateTime;

class SleepService
{
	
	public function intervalSleepEveryDayByTime(string $time){
		$date = date('Y-m-d', strtotime('+1 day'));
		$string = $date . ' ' . $time . ':00';
        return  (int)strtotime($string) - time() ;
    }
    public function intervalSleep($interval)
    {
        if ($interval === 'everyMinute') {
            $nowHour = date('Y-m-d H:i', strtotime('+1 minute'));
            $string = $nowHour . ':00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyFiveMinute') {
            $minute =  5 - date('i') % 5;
            $nowHour = date('Y-m-d H:i', strtotime('+'.$minute.' minute'));
            $string = $nowHour . ':00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyFifteenMinute') {
            $minute =  15 - date('i') % 15;
            $nowHour = date('Y-m-d H:i', strtotime('+'.$minute.' minute'));
            $string = $nowHour . ':00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyThirtyMinute') {
            $minute =  30 - date('i') % 30;
            $nowHour = date('Y-m-d H:i', strtotime('+'.$minute.' minute'));
            $string = $nowHour . ':00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyHour') {
            $nowHour = date('Y-m-d H', strtotime('+1 hour'));
            $string = $nowHour . ':00:00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyFourHours') {
            $hour = 4 - date('H') % 4;
            $nowHour = date('Y-m-d H', strtotime('+'.$hour.' hour'));
            $string = $nowHour . ':00:00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyTwelveHours') {
            $hour = 12 - date('H') % 12;
            $nowHour = date('Y-m-d H', strtotime('+'.$hour.' hour'));
            $string = $nowHour . ':00:00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyDay') {
            $day = date('Y-m-d', strtotime('+1 day'));
            $string = $day . ' 06:30:00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyWeek') {
           
            $day = 8 - (int)date('w');
          
            if ($day == 8) {
                $day = 1;
            }
            $days = date('Y-m-d', strtotime('+'.$day.' day'));
            $string = $days . ' 06:30:00';
                return  (int)strtotime($string) - time() ;
        }
        if ($interval === 'everyMonth') {
            $days = date('Y-m', strtotime('+1 month'));
            $string = $days . '-01 06:30:00';
                return  (int)strtotime($string) - time() ;
        }
    }
}
