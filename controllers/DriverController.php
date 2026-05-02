<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/DriverModel.php';

class DriverController extends Controller {
    private DriverModel $model;

    public function __construct() {
        $this->model = new DriverModel();
    }

    public function index(): void {
        $this->requireAuth();
        $data = $this->model->getAll();
        $this->success($data);
    }

    public function active(): void {
        $this->requireAuth();
        $data = $this->model->getActive();
        $this->success($data);
    }

    public function show(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        $record = $this->model->find($id);
        $record ? $this->success($record) : $this->error('السائق غير موجود', 404);
    }

    public function store(): void {
        $this->requireAuth();
        $input = $this->getInput();
        $this->validateRequired($input, ['name']);
        $result = $this->model->create($input);
        $this->json($result, $result['status'] === 'success' ? 201 : 400);
    }

    public function update(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        $input = $this->getInput();
        $result = $this->model->update($id, $input);
        $this->json($result);
    }

    public function destroy(): void {
        $this->requireAdmin();
        $id = (int)$this->getParam('id');
        $result = $this->model->delete($id);
        $this->json($result);
    }

    public function search(): void {
        $this->requireAuth();
        $q = $this->getParam('q', '');
        $data = $this->model->search($q);
        $this->success($data);
    }

    public function dailySummary(): void {
        $this->requireAuth();
        $driverId = (int)$this->getParam('driver_id');
        $date = $this->getParam('date', date('Y-m-d'));
        $data = $this->model->getDailySummary($driverId, $date);
        $this->success($data);
    }

    /**
     * Driver Leaderboard - Ranking drivers by performance
     * Scoring: trips (30%) + cash collection (40%) + collection rate (30%)
     */
    public function leaderboard(): void {
        $this->requireAuth();
        $fromDate = $this->getParam('from_date', date('Y-m-01'));
        $toDate   = $this->getParam('to_date',   date('Y-m-d'));

        $db = Database::getInstance();

        $drivers = $db->fetchAll(
            "SELECT 
                d.id,
                d.name,
                d.phone,
                COALESCE(COUNT(DISTINCT t.id), 0)                         AS trip_count,
                COALESCE(SUM(i.net_amount), 0)                            AS total_sales,
                COALESCE(SUM(i.paid_amount), 0)                           AS total_cash,
                COALESCE(SUM(i.due_amount), 0)                            AS total_credit,
                COALESCE(SUM(t.commission_amount), 0)                     AS total_commission,
                CASE WHEN COALESCE(SUM(i.net_amount), 0) > 0
                     THEN ROUND((COALESCE(SUM(i.paid_amount), 0) / SUM(i.net_amount)) * 100, 1)
                     ELSE 0 END                                           AS collection_rate
            FROM Drivers d
            LEFT JOIN Trips t ON t.driver_id = d.id
                AND t.trip_date::date BETWEEN ?::date AND ?::date
            LEFT JOIN Invoices i ON i.trip_id = t.id
                AND (i.is_voided IS NULL OR i.is_voided = false)
            WHERE d.is_active = true
            GROUP BY d.id, d.name, d.phone
            ORDER BY total_cash DESC",
            [$fromDate, $toDate]
        );

        // Calculate score: trips(30%) + cash(40%) + collection_rate(30%)
        $maxTrips = max(array_column($drivers, 'trip_count') ?: [1]);
        $maxCash  = max(array_column($drivers, 'total_cash') ?: [1]);

        foreach ($drivers as &$driver) {
            $tripScore       = $maxTrips > 0 ? ((float)$driver['trip_count'] / $maxTrips) * 30 : 0;
            $cashScore       = $maxCash  > 0 ? ((float)$driver['total_cash']  / $maxCash)  * 40 : 0;
            $collectionScore = ((float)$driver['collection_rate'] / 100) * 30;
            $driver['score'] = round($tripScore + $cashScore + $collectionScore, 1);
        }
        unset($driver);

        // Sort by score descending
        usort($drivers, fn($a, $b) => $b['score'] <=> $a['score']);

        // Add rank
        foreach ($drivers as $i => &$driver) {
            $driver['rank'] = $i + 1;
        }
        unset($driver);

        $this->success([
            'from_date' => $fromDate,
            'to_date'   => $toDate,
            'drivers'   => $drivers
        ]);
    }
}
