<?php
require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../models/InvoiceModel.php';
require_once __DIR__ . '/../models/TripModel.php';
require_once __DIR__ . '/../models/CustomerModel.php';
require_once __DIR__ . '/../services/FundService.php';

class InvoiceController extends Controller {
    private InvoiceModel $model;
    private TripModel $tripModel;
    private CustomerModel $customerModel;
    private FundService $fundService;

    public function __construct() {
        $this->model = new InvoiceModel();
        $this->tripModel = new TripModel();
        $this->customerModel = new CustomerModel();
        $this->fundService = new FundService();
    }

    public function index(): void {
        $this->requireAuth();
        $filters = [];
        if ($this->getParam('trip_id')) $filters['trip_id'] = $this->getParam('trip_id');
        if ($this->getParam('customer_id')) $filters['customer_id'] = $this->getParam('customer_id');
        if ($this->getParam('date')) $filters['date'] = $this->getParam('date');
        if ($this->getParam('from_date')) $filters['from_date'] = $this->getParam('from_date');
        if ($this->getParam('to_date')) $filters['to_date'] = $this->getParam('to_date');
        
        $data = $this->model->getAllWithDetails($filters);
        $this->success($data);
    }

    public function show(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        $record = $this->model->find($id);
        $record ? $this->success($record) : $this->error('الفاتورة غير موجودة', 404);
    }

    public function store(): void {
        $user = $this->requireAuth();
        $input = $this->getInput();
        $this->validateRequired($input, ['trip_id', 'customer_id', 'quantity_m3', 'total_amount', 'net_amount']);
        $this->validatePositiveAmounts($input, ['quantity_m3', 'total_amount', 'discount_amount', 'net_amount', 'paid_amount', 'due_amount']);

        // Validate: Trip must be Open
        $trip = $this->tripModel->find((int)$input['trip_id']);
        if (!$trip) {
            $this->error('الرحلة غير موجودة');
        }
        if ($trip['status'] !== 'Open') {
            $this->error('لا يمكن إضافة فاتورة لرحلة مغلقة');
        }

        // Validate customer exists
        $customer = $this->customerModel->find((int)$input['customer_id']);
        if (!$customer) {
            $this->error('الزبون غير موجود');
        }

        // Server-side recalculation (prevents client-side tampering)
        $totalAmount    = (float)$input['total_amount'];
        $discountAmount = (float)($input['discount_amount'] ?? 0);
        $netAmount      = $totalAmount - $discountAmount;
        $paidAmount     = (float)($input['paid_amount'] ?? 0);
        $dueAmount      = $netAmount - $paidAmount;

        // Validate: paid_amount cannot exceed net_amount
        if ($paidAmount > $netAmount) {
            $this->error('المبلغ المدفوع لا يمكن أن يتجاوز صافي الفاتورة');
        }

        $input['net_amount'] = $netAmount;
        $input['due_amount'] = $dueAmount;
        $input['is_voided']  = false;

        $db = Database::getInstance();
        
        try {
            $db->beginTransaction();

            // Create invoice
            $result = $this->model->create($input);
            
            if ($result['status'] !== 'success') {
                $db->rollBack();
                $this->json($result, 400);
            }

            $invoiceId = $result['data']['id'];

            // Update customer balance if there's a due amount
            if ($dueAmount > 0) {
                $this->customerModel->addToBalance((int)$input['customer_id'], $dueAmount);
            }

            // Record cash payment in fund (if paid_amount > 0)
            if ($paidAmount > 0) {
                $this->fundService->onCashInvoice($invoiceId, $paidAmount);
            }

            // Update customer lifetime paid
            if ($paidAmount > 0) {
                $this->customerModel->addToLifetimePaid((int)$input['customer_id'], $paidAmount);
            }

            $db->commit();
            
            // Return fresh data
            $result['data'] = $this->model->find($invoiceId);
            $result['data']['customer_balance'] = $this->customerModel->find((int)$input['customer_id'])['balance'];
            $this->json($result, 201);

        } catch (\Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $this->error('خطأ في حفظ الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    public function update(): void {
        $this->requireAuth();
        $id = (int)$this->getParam('id');
        $input = $this->getInput();
        if (isset($input['total_amount']) || isset($input['paid_amount'])) {
            $this->validatePositiveAmounts($input, ['total_amount', 'discount_amount', 'net_amount', 'paid_amount', 'due_amount']);
        }
        $result = $this->model->update($id, $input);
        $this->json($result);
    }

    /**
     * VOID Invoice - Safe cancellation with full accounting reversal
     * Marks invoice as voided and reverses all financial effects on customer balance and fund
     */
    public function void(): void {
        $user = $this->requireAdmin();
        $input = $this->getInput();
        $id = (int)($input['id'] ?? $this->getParam('id'));

        $invoice = $this->model->find($id);
        if (!$invoice) {
            $this->error('الفاتورة غير موجودة', 404);
        }
        if (!empty($invoice['is_voided'])) {
            $this->error('هذه الفاتورة ملغاة مسبقاً');
        }

        $db = Database::getInstance();

        try {
            $db->beginTransaction();

            // Mark invoice as voided
            $db->query(
                "UPDATE Invoices SET is_voided = true, voided_at = NOW(), voided_by = ? WHERE id = ?",
                [$user['id'], $id]
            );

            $dueAmount  = (float)$invoice['due_amount'];
            $paidAmount = (float)$invoice['paid_amount'];

            // Reverse customer balance: remove the due amount that was added
            if ($dueAmount > 0) {
                $this->customerModel->deductFromBalance((int)$invoice['customer_id'], $dueAmount);
            }

            // Reverse fund: record outflow to cancel the cash that was recorded
            if ($paidAmount > 0) {
                $this->fundService->onVoidInvoice($id, $paidAmount);
                // Reverse lifetime paid
                $db->query(
                    "UPDATE Customers SET total_lifetime_paid = GREATEST(0, total_lifetime_paid - ?) WHERE id = ?",
                    [$paidAmount, $invoice['customer_id']]
                );
            }

            $db->commit();
            $this->success(['id' => $id], 'تم إلغاء الفاتورة وعكس جميع التأثيرات المالية بنجاح');

        } catch (\Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $this->error('خطأ في إلغاء الفاتورة: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(): void {
        $this->requireAdmin();
        $id = (int)$this->getParam('id');
        // Safety check: require voiding before hard delete
        $invoice = $this->model->find($id);
        if ($invoice && empty($invoice['is_voided'])) {
            $this->error('يجب إلغاء الفاتورة أولاً قبل حذفها نهائياً. استخدم خيار الإلغاء (Void).');
        }
        $result = $this->model->delete($id);
        $this->json($result);
    }

    public function search(): void {
        $this->requireAuth();
        $this->index();
    }

    public function byTrip(): void {
        $this->requireAuth();
        $tripId = (int)$this->getParam('trip_id');
        $data = $this->model->getByTrip($tripId);
        $this->success($data);
    }

    public function driverCashSales(): void {
        $this->requireAuth();
        $driverId = (int)$this->getParam('driver_id');
        $date = $this->getParam('date', date('Y-m-d'));
        $data = $this->model->getDriverCashSales($driverId, $date);
        $this->success($data);
    }

    public function salesSummary(): void {
        $this->requireAuth();
        $groupBy = $this->getParam('group_by', 'day');
        $fromDate = $this->getParam('from_date');
        $toDate = $this->getParam('to_date');
        $data = $this->model->getSalesSummary($groupBy, $fromDate, $toDate);
        $this->success($data);
    }

    public function waterConsumption(): void {
        $this->requireAuth();
        $fromDate = $this->getParam('from_date');
        $toDate = $this->getParam('to_date');
        $data = $this->model->getWaterConsumption($fromDate, $toDate);
        $this->success($data);
    }
}
