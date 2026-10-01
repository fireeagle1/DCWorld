<?php
// Prevent redeclaring functions by checking if they are already defined
if (!function_exists('getMonths')) {
    function getMonths($link) {
        $query = "SELECT DISTINCT DATE_FORMAT(session_date, '%Y-%m') AS month FROM charging_sessions ORDER BY session_date DESC";
        $result = $link->query($query);
        $months = [];
        while ($row = $result->fetch_assoc()) {
            $months[] = $row['month'];
        }
        return $months;
    }
}

if (!function_exists('getSessionsByMonth')) {
    function getSessionsByMonth($link, $month) {
        $query = "SELECT * FROM charging_sessions WHERE DATE_FORMAT(session_date, '%Y-%m') = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('s', $month);
        $stmt->execute();
        return $stmt->get_result();
    }
}

if (!function_exists('getTotalCostByMonth')) {
    function getTotalCostByMonth($link, $month) {
        $query = "SELECT SUM(total_cost) AS total_cost FROM charging_sessions WHERE DATE_FORMAT(session_date, '%Y-%m') = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('s', $month);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc()['total_cost'];
    }
}

if (!function_exists('isMonthPaid')) {
    function isMonthPaid($link, $month) {
        $query = "SELECT is_paid FROM payments WHERE month = ?";
        $stmt = $link->prepare($query);
        $stmt->bind_param('s', $month);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row ? $row['is_paid'] : false;
    }
}
