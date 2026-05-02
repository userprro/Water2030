<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/SettlementModel.php';
require_once __DIR__ . '/../models/CustomerModel.php';
require_once __DIR__ . '/../models/DriverModel.php';
require_once __DIR__ . '/../models/InvoiceModel.php';
require_once __DIR__ . '/../models/ExpenseModel.php';
require_once __DIR__ . '/../services/FundService.php';

class SettlementController extends Controller {
    private SettlementModel $model;
    private CustomerModel $customerModel;
    private DriverModel $driverModel;
    private InvoiceModel $invoiceModel;
    private ExpenseModel $expenseModel;
    private FundService $fundService;

    public function __construct() {
        $this->model = new SettlementModel();
        $this->customerModel = new CustomerModel();
        $this->driverModel = new DriverModel();
        $this->invoiceModel = new InvoiceModel();
        $this->expenseModel = new ExpenseModel();
        $this->fundService = new FundService();
    }

    public function index(): void {
        $this->requireAuth();
        $filters = [];
        if ($this->getParam('driver_id')) $filters['driver_id'] = $this->getParam('driver_id');
        if ($this->getParam('date')) $filters['date'] = $this->getParam('date');
        $data = $this->model->getAllWithDetails($filters);
        $this->success($data);
    }

    public function show(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        $settlement = $this->model->find($id);
        if (!$settlement) {
            $this->error('سند التصفية غير موجود', 404);
        }
        $details = $this->model->getDetails($id);
        $settlement['details'] = $details;
        $this->success($settlement);
    }

    /**
     * Get driver settlement data (before creating)
     */
    public function prepare(): void {
        $this->requireAuth();
        $driverId = (int)$this->getParam('driver_id');
        $date = $this->getParam('date', date('Y-m-d'));

        $driver = $this->driverModel->find($driverId);
        if (!$driver) {
            $this->error('السائق غير موجود');
        }

        // Get daily summary
        $summary = $this->driverModel->getDailySummary($driverId, $date);
        
        // Get cash sales details
        $cashSales = $this->invoiceModel->getDriverCashSales($driverId, $date);
        
        // Get driver expenses
        $expenses = $this->expenseModel->getDriverExpenses($driverId, $date);

        $this->success([
            'driver' => $driver,
            'date' => $date,
            'summary' => $summary,
            'cash_sales' => $cashSales,
            'expenses' => $expenses
        ]);
    }

    /**
     * Create settlement with details (Transaction)
     */
    public function store(): void {
        $user = $this->requireAuth();
        $input = $this->getInput();
        $this->validateRequired($input, ['driver_id', 'total_amount_received']);
        $this->validatePositiveAmounts($input, ['total_amount_received']);

        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Create the settlement record
            $settlementData = [
                'driver_id' => $input['driver_id'],
                'total_amount_received' => $input['total_amount_received'],
                'accountant_id' => $user['id']
            ];
            $result = $this->model->create($settlementData);
            
            if ($result['status'] !== 'success') {
                $db->rollBack();
                $this->json($result, 400);
            }

            $settlementId = $result['data']['id'];

            // Process collection details (debt payments)
            if (isset($input['details']) && is_array($input['details'])) {
                foreach ($input['details'] as $detail) {
                    $this->validatePositiveAmounts($detail, ['amount_paid', 'discount_amount']);
                    
                    $amountPaid = (float)$detail['amount_paid'];
                    $discountAmount = (float)($detail['discount_amount'] ?? 0);

                    // Add detail record
                    $this->model->addDetail([
                        'settlement_id' => $settlementId,
                        'customer_id' => $detail['customer_id'],
                        'amount_paid' => $amountPaid,
                        'payment_type' => $detail['payment_type'] ?? 'سداد دين سابق',
                        'discount_amount' => $discountAmount
                    ]);

                    // Deduct (amount_paid + discount) from customer balance
                    $totalDeduction = $amountPaid + $discountAmount;
                    $this->customerModel->deductFromBalance((int)$detail['customer_id'], $totalDeduction);

                    // Add to customer lifetime paid (only amount_paid, not discount)
                    $this->customerModel->addToLifetimePaid((int)$detail['customer_id'], $amountPaid);
                }
            }

            // Record in fund transactions
            if ((float)$input['total_amount_received'] > 0) {
                $this->fundService->onSettlement($settlementId, (float)$input['total_amount_received']);
            }

            $db->commit();

            $result['data'] = $this->model->find($settlementId);
            $result['data']['details'] = $this->model->getDetails($settlementId);
            $this->json($result, 201);

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->error('خطأ في حفظ التصفية: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Add a single collection detail to existing settlement
     */
    public function addDetail(): void {
        $user = $this->requireAuth();
        $input = $this->getInput();
        $this->validateRequired($input, ['settlement_id', 'customer_id', 'amount_paid']);
        $this->validatePositiveAmounts($input, ['amount_paid', 'discount_amount']);

        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            $amountPaid = (float)$input['amount_paid'];
            $discountAmount = (float)($input['discount_amount'] ?? 0);

            $this->model->addDetail([
                'settlement_id' => $input['settlement_id'],
                'customer_id' => $input['customer_id'],
                'amount_paid' => $amountPaid,
                'payment_type' => $input['payment_type'] ?? 'سداد دين سابق',
                'discount_amount' => $discountAmount
            ]);

            // Deduct from customer balance
            $totalDeduction = $amountPaid + $discountAmount;
            $this->customerModel->deductFromBalance((int)$input['customer_id'], $totalDeduction);
            $this->customerModel->addToLifetimePaid((int)$input['customer_id'], $amountPaid);

            $db->commit();

            // Return updated customer data
            $customer = $this->customerModel->find((int)$input['customer_id']);
            $this->success([
                'customer_balance' => $customer['balance'],
                'customer_lifetime_paid' => $customer['total_lifetime_paid']
            ], 'تم تسجيل الدفعة بنجاح');

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->error('خطأ في تسجيل الدفعة: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Print settlement receipt data
     */
    public function printReceipt(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        
        $settlement = $this->model->find($id);
        if (!$settlement) {
            $this->error('سند التصفية غير موجود', 404);
        }

        $driver = $this->driverModel->find($settlement['driver_id']);
        $details = $this->model->getDetails($id);
        $date = substr($settlement['settlement_date'], 0, 10);
        
        // Get driver's cash sales for that date
        $cashSales = $this->invoiceModel->getDriverCashSales($settlement['driver_id'], $date);
        $expenses = $this->expenseModel->getDriverExpenses($settlement['driver_id'], $date);
        $summary = $this->driverModel->getDailySummary($settlement['driver_id'], $date);

        $this->success([
            'settlement' => $settlement,
            'driver' => $driver,
            'details' => $details,
            'cash_sales' => $cashSales,
            'expenses' => $expenses,
            'summary' => $summary
        ]);
    }

    public function search(): void {
        $this->requireAuth();
        $this->index();
    }

    /**
     * VOID Settlement - Safe cancellation with full accounting reversal
     * Reverses customer balance deductions and fund income
     */
    public function void(): void {
        $user = $this->requireAdmin();
        $input = $this->getInput();
        $id = (int)($input['id'] ?? $this->getParam('id'));

        $settlement = $this->model->find($id);
        if (!$settlement) {
            $this->error('سند التصفية غير موجود', 404);
        }
        if (!empty($settlement['is_voided'])) {
            $this->error('هذا السند ملغى مسبقاً');
        }

        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Get all details to reverse customer balances
            $details = $this->model->getDetails($id);

            foreach ($details as $detail) {
                $amountPaid     = (float)$detail['amount_paid'];
                $discountAmount = (float)$detail['discount_amount'];
                $totalDeduction = $amountPaid + $discountAmount;

                // Reverse: add back the deducted amount to customer balance
                if ($totalDeduction > 0) {
                    $this->customerModel->addToBalance((int)$detail['customer_id'], $totalDeduction);
                }
                // Reverse lifetime paid
                if ($amountPaid > 0) {
                    $db->query(
                        "UPDATE Customers SET total_lifetime_paid = GREATEST(0, total_lifetime_paid - ?) WHERE id = ?",
                        [$amountPaid, $detail['customer_id']]
                    );
                }
            }

            // Reverse fund income
            $totalReceived = (float)$settlement['total_amount_received'];
            if ($totalReceived > 0) {
                $this->fundService->onVoidSettlement($id, $totalReceived);
            }

            // Mark settlement as voided
            $db->query(
                "UPDATE Driver_Settlements SET is_voided = true, voided_at = NOW(), voided_by = ? WHERE id = ?",
                [$user['id'], $id]
            );

            $db->commit();
            $this->success(['id' => $id], 'تم إلغاء سند التصفية وعكس جميع التأثيرات المالية بنجاح');

        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->error('خطأ في إلغاء سند التصفية: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(): void {
        $this->requireAdmin();
        $id = (int)$this->getParam('id');
        // Safety check: require voiding before hard delete
        $settlement = $this->model->find($id);
        if ($settlement && empty($settlement['is_voided'])) {
            $this->error('يجب إلغاء سند التصفية أولاً قبل حذفه نهائياً.');
        }
        $result = $this->model->delete($id);
        $this->json($result);
    }

    public function update(): void {
        $this->requireAuth();
        $this->error('لا يمكن تعديل سند التصفية');
    }
}
