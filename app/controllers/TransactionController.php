<?php


use FireflyIII\Exception\FireflyException;
use Illuminate\Support\Collection;
use Illuminate\Support\MessageBag;

/**
 * Class TransactionController
 *
 */
class TransactionController extends BaseController
{


    /**
     * Construct a new transaction controller with two of the most often used helpers.
     *
     */
    public function __construct()
    {
        View::share('title', 'Transactions');
        View::share('mainTitleIcon', 'fa-repeat');
    }

    // TODO this needs cleaning up and thinking over.
    public function alreadyRelated(TransactionJournal $journal)
    {

        $ids = [];
        /** @var TransactionGroup $group */
        foreach ($journal->transactiongroups()->get() as $group) {
            /** @var TransactionJournal $jrnl */
            foreach ($group->transactionjournals()->get() as $jrnl) {
                if ($jrnl->id != $journal->id) {
                    $ids[] = $jrnl->id;
                }
            }
        }
        $unique = array_unique($ids);
        if (count($ids) > 0) {

            /** @var \FireflyIII\Database\TransactionJournal $repository */
            $repository = App::make('FireflyIII\Database\TransactionJournal');
            $set        = $repository->getByIds($ids);
            $set->each(
                function (TransactionJournal $journal) {
                    $journal->amount = mf($journal->getAmount());
                }
            );

            return Response::json($set->toArray());
        } else {
            return (new Collection)->toArray();
        }
    }

    /**
     * Shows the view helping the user to create a new transaction journal.
     *
     * @param string $what
     *
     * @return \Illuminate\View\View
     */
    public function create($what = 'deposit')
    {
        /*
         * The repositories we need:
         */

        /** @var \FireflyIII\Database\Account $accountRepository */
        $accountRepository = App::make('FireflyIII\Database\Account');

        /** @var \FireflyIII\Database\Budget $budgetRepository */
        $budgetRepository = App::make('FireflyIII\Database\Budget');

        /** @var \FireflyIII\Database\Piggybank $piggyRepository */
        $piggyRepository = App::make('FireflyIII\Database\Piggybank');

        /** @var \FireflyIII\Database\RepeatedExpense $repRepository */
        $repRepository = App::make('FireflyIII\Database\RepeatedExpense');

        // get asset accounts with names and id's .
        $assetAccounts = FFForm::makeSelectList($accountRepository->getAssetAccounts());

        // get budgets as a select list.
        $budgets    = FFForm::makeSelectList($budgetRepository->get());
        $budgets[0] = '(no budget)';

        // get the piggy banks.
        $list       = $piggyRepository->get()->merge($repRepository->get());
        $piggies    = FFForm::makeSelectList($list);
        $piggies[0] = '(no piggy bank)';
        asort($piggies);

        /*
         * respond to a possible given values in the URL.
         */
        $prefilled = Session::has('prefilled') ? Session::get('prefilled') : [];
        $respondTo = ['account_id', 'account_from_id'];
        foreach ($respondTo as $r) {
            if (!is_null(Input::get($r))) {
                $prefilled[$r] = Input::get($r);
            }
        }
        Session::put('prefilled', $prefilled);

        return View::make('transactions.create')->with('accounts', $assetAccounts)->with('budgets', $budgets)->with('what', $what)->with('piggies', $piggies)
                   ->with('subTitle', 'Add a new ' . $what);
    }

    /**
     * Shows the form that allows a user to delete a transaction journal.
     *
     * @param TransactionJournal $transactionJournal
     *
     * @return $this
     */
    public function delete(TransactionJournal $transactionJournal)
    {
        $type = strtolower($transactionJournal->transactionType->type);

        return View::make('transactions.delete')->with('journal', $transactionJournal)->with(
            'subTitle', 'Delete ' . $type . ' "' . $transactionJournal->description . '"'
        );


    }

    /**
     * @param TransactionJournal $transactionJournal
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(TransactionJournal $transactionJournal)
    {
        $type = $transactionJournal->transactionType->type;

        /** @var \FireflyIII\Database\TransactionJournal $repository */
        $repository = App::make('FireflyIII\Database\TransactionJournal');
        $repository->destroy($transactionJournal);


        switch ($type) {
            case 'Withdrawal':
                return Redirect::route('transactions.index', 'withdrawal');
                break;
            case 'Deposit':
                return Redirect::route('transactions.index', 'deposit');
                break;
            case 'Transfer':
                return Redirect::route('transactions.index', 'transfers');
                break;
        }
    }

    // TODO this needs cleaning up and thinking over.
    public function doRelate()
    {
        $id     = intval(Input::get('id'));
        $sister = intval(Input::get('relateTo'));

        /** @var \FireflyIII\Database\TransactionJournal $repository */
        $repository = App::make('FireflyIII\Database\TransactionJournal');

        $journal = $repository->find($id);
        $sis     = $repository->find($sister);

        if ($journal && $sis) {
            $group           = new TransactionGroup;
            $group->relation = 'balance';
            $group->user_id  = $repository->getUser()->id;
            $group->save();
            $group->transactionjournals()->save($journal);
            $group->transactionjournals()->save($sis);

            return Response::json(true);
        }

        return Response::json(false);


    }

    /**
     * Shows the view to edit a transaction.
     *
     * @param TransactionJournal $journal
     *
     * @return $this
     */
    public function edit(TransactionJournal $journal)
    {
        /*
         * All the repositories we need:
         */

        /** @var \FireflyIII\Database\Account $accountRepository */
        $accountRepository = App::make('FireflyIII\Database\Account');

        /** @var \FireflyIII\Database\Budget $budgetRepository */
        $budgetRepository = App::make('FireflyIII\Database\Budget');

        /** @var \FireflyIII\Database\Piggybank $piggyRepository */
        $piggyRepository = App::make('FireflyIII\Database\Piggybank');


        // type is useful for display:
        $what = strtolower($journal->transactiontype->type);

        // get asset accounts with names and id's.
        $accounts = FFForm::makeSelectList($accountRepository->getAssetAccounts());

        // get budgets as a select list.
        $budgets    = FFForm::makeSelectList($budgetRepository->get());
        $budgets[0] = '(no budget)';

        /*
         * Get all piggy banks plus (if any) the relevant piggy bank. Since just one
         * of the transactions in the journal has this field, it should all fill in nicely.
         */
        // get the piggy banks.
        $piggies     = FFForm::makeSelectList($piggyRepository->get());
        $piggies[0]  = '(no piggy bank)';
        $piggyBankId = 0;
        foreach ($journal->transactions as $t) {
            if (!is_null($t->piggybank_id)) {
                $piggyBankId = $t->piggybank_id;
            }
        }

        /*
         * Data to properly display the edit form.
         */
        $prefilled = [
            'date'         => $journal->date->format('Y-m-d'),
            'category'     => '',
            'budget_id'    => 0,
            'piggybank_id' => $piggyBankId
        ];

        /*
         * Fill in the category.
         */
        $category = $journal->categories()->first();
        if (!is_null($category)) {
            $prefilled['category'] = $category->name;
        }

        /*
         * Switch on the type of transaction edited by the user and fill in other
         * relevant fields:
         */
        switch ($what) {
            case 'withdrawal':
                if (floatval($journal->transactions[0]->amount) < 0) {
                    // transactions[0] is the asset account that paid for the withdrawal.
                    $prefilled['account_id']      = $journal->transactions[0]->account->id;
                    $prefilled['expense_account'] = $journal->transactions[1]->account->name;
                    $prefilled['amount']          = floatval($journal->transactions[1]->amount);
                } else {
                    // transactions[1] is the asset account that paid for the withdrawal.
                    $prefilled['account_id']      = $journal->transactions[1]->account->id;
                    $prefilled['expense_account'] = $journal->transactions[0]->account->name;
                    $prefilled['amount']          = floatval($journal->transactions[0]->amount);
                }


                $budget = $journal->budgets()->first();
                if (!is_null($budget)) {
                    $prefilled['budget_id'] = $budget->id;
                }
                break;
            case 'deposit':
                if (floatval($journal->transactions[0]->amount) < 0) {
                    // transactions[0] contains the account the money came from.
                    $prefilled['account_id']      = $journal->transactions[1]->account->id;
                    $prefilled['revenue_account'] = $journal->transactions[0]->account->name;
                    $prefilled['amount']          = floatval($journal->transactions[1]->amount);
                } else {
                    // transactions[1] contains the account the money came from.
                    $prefilled['account_id']      = $journal->transactions[0]->account->id;
                    $prefilled['revenue_account'] = $journal->transactions[1]->account->name;
                    $prefilled['amount']          = floatval($journal->transactions[0]->amount);

                }

                break;
            case 'transfer':
                if (floatval($journal->transactions[0]->amount) < 0) {
                    // zero = from account.
                    $prefilled['account_from_id'] = $journal->transactions[0]->account->id;
                    $prefilled['account_to_id']   = $journal->transactions[1]->account->id;
                    $prefilled['amount']          = floatval($journal->transactions[1]->amount);
                } else {
                    // one = from account
                    $prefilled['account_from_id'] = $journal->transactions[1]->account->id;
                    $prefilled['account_to_id']   = $journal->transactions[0]->account->id;
                    $prefilled['amount']          = floatval($journal->transactions[0]->amount);
                }
                if ($journal->piggybankevents()->count() > 0) {
                    $prefilled['piggybank_id'] = $journal->piggybankevents()->first()->piggybank_id;
                }
                break;
        }

        /*
         * Show the view.
         */

        return View::make('transactions.edit')->with('journal', $journal)->with('accounts', $accounts)->with(
            'what', $what
        )->with('budgets', $budgets)->with('data', $prefilled)->with('piggies', $piggies)->with(
            'subTitle', 'Edit ' . $what . ' "' . $journal->description . '"'
        );
    }

    /**
     * @param $what
     *
     * @return $this
     */
    public function index($what)
    {

        /** @var \FireflyIII\Database\TransactionJournal $repository */
        $repository = App::make('FireflyIII\Database\TransactionJournal');

        switch ($what) {
            case 'expenses':
            case 'withdrawal':
                $subTitleIcon = 'fa-long-arrow-left';
                $subTitle     = 'Expenses';
                $journals     = $repository->getWithdrawalsPaginated(50);
                break;
            case 'revenue':
            case 'deposit':
                $subTitleIcon = 'fa-long-arrow-right';
                $subTitle     = 'Revenue, income and deposits';
                $journals     = $repository->getDepositsPaginated(50);
                break;
            case 'transfer':
            case 'transfers':
                $subTitleIcon = 'fa-arrows-h';
                $subTitle     = 'Transfers';
                $journals     = $repository->getTransfersPaginated(50);
                break;
        }

        return View::make('transactions.index', compact('subTitle', 'what', 'subTitleIcon', 'journals'));

    }

    public function relate(TransactionJournal $journal)
    {
        $groups  = $journal->transactiongroups()->get();
        $members = new Collection;
        /** @var TransactionGroup $group */
        foreach ($groups as $group) {
            /** @var TransactionJournal $jrnl */
            foreach ($group->transactionjournals()->get() as $jrnl) {
                if ($jrnl->id != $journal->id) {
                    $members->push($jrnl);
                }
            }
        }

        return View::make('transactions.relate', compact('journal', 'members'));
    }

    // TODO this needs cleaning up and thinking over.
    public function relatedSearch(TransactionJournal $journal)
    {
        $search = e(trim(Input::get('searchValue')));

        /** @var \FireflyIII\Database\TransactionJournal $repository */
        $repository = App::make('FireflyIII\Database\TransactionJournal');

        $result = $repository->searchRelated($search, $journal);
        $result->each(
            function (TransactionJournal $j) {
                $j->amount = mf($j->getAmount());
            }
        );

        return Response::json($result->toArray());
    }

    /**
     * @param TransactionJournal $journal
     *
     * @return $this
     */
    public function show(TransactionJournal $journal)
    {
        $journal->transactions->each(
            function (\Transaction $t) use ($journal) {
                $t->before = floatval(
                    $t->account->transactions()->leftJoin(
                        'transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id'
                    )->where('transaction_journals.date', '<=', $journal->date->format('Y-m-d'))->where(
                        'transaction_journals.created_at', '<=', $journal->created_at->format('Y-m-d H:i:s')
                    )->where('transaction_journals.id', '!=', $journal->id)->sum('transactions.amount')
                );
                $t->after  = $t->before + $t->amount;
            }
        );
        $members = new Collection;
        /** @var TransactionGroup $group */
        foreach ($journal->transactiongroups()->get() as $group) {
            /** @var TransactionJournal $jrnl */
            foreach ($group->transactionjournals()->get() as $jrnl) {
                if ($jrnl->id != $journal->id) {
                    $members->push($jrnl);
                }
            }
        }

        return View::make('transactions.show', compact('journal', 'members'))->with(
            'subTitle', $journal->transactionType->type . ' "' . $journal->description . '"'
        );
    }

    /**
     * @param $what
     *
     * @return $this|\Illuminate\Http\RedirectResponse
     * @throws FireflyException
     */
    public function store($what)
    {
        $data             = Input::except('_token');
        $data['what']     = $what;
        $data['currency'] = 'EUR';

        /** @var \FireflyIII\Database\TransactionJournal $repository */
        $repository = App::make('FireflyIII\Database\TransactionJournal');

        switch ($data['post_submit_action']) {
            default:
                throw new FireflyException('Cannot handle post_submit_action "' . e($data['post_submit_action']) . '"');
                break;
            case 'create_another':
            case 'store':
                $messages = $repository->validate($data);
                /** @var MessageBag $messages ['errors'] */
                if ($messages['errors']->count() > 0) {
                    Session::flash('warnings', $messages['warnings']);
                    Session::flash('successes', $messages['successes']);
                    Session::flash('error', 'Could not save transaction: ' . $messages['errors']->first());

                    return Redirect::route('transactions.create', $what)->withInput()->withErrors($messages['errors']);
                }
                // store!
                $journal = $repository->store($data);
                Session::flash('success', 'New transaction stored!');

                /*
                 * Trigger a search for the related (if selected)
                 * piggy bank and store an event.
                 */
                $piggyID = null;
                if (!is_null(Input::get('piggybank_id')) && intval(Input::get('piggybank_id')) > 0) {
                    $piggyID = intval(Input::get('piggybank_id'));
                }
                Event::fire('transactionJournal.store', [$journal, $piggyID]); // new and used.
                /*
                 * Also trigger on both transactions.
                 */
                /** @var Transaction $transaction */
                foreach ($journal->transactions as $transaction) {
                    Event::fire('transaction.store', [$transaction]);
                }

                if ($data['post_submit_action'] == 'create_another') {
                    return Redirect::route('transactions.create', $what)->withInput();
                } else {
                    return Redirect::route('transactions.index', $what);
                }
                break;
            case 'validate_only':
                $messageBags = $repository->validate($data);
                Session::flash('warnings', $messageBags['warnings']);
                Session::flash('successes', $messageBags['successes']);
                Session::flash('errors', $messageBags['errors']);

                return Redirect::route('transactions.create', $what)->withInput();
                break;
        }
    }

    // TODO this needs cleaning up and thinking over.
    public function unrelate(TransactionJournal $journal)
    {
        $groups    = $journal->transactiongroups()->get();
        $relatedTo = intval(Input::get('relation'));
        /** @var TransactionGroup $group */
        foreach ($groups as $group) {
            foreach ($group->transactionjournals()->get() as $jrnl) {
                if ($jrnl->id == $relatedTo) {
                    // remove from group:
                    $group->transactionjournals()->detach($relatedTo);
                }
            }
            if ($group->transactionjournals()->count() == 1) {
                $group->delete();
            }
        }
        return Response::json(true);

    }

    /**
     * @param TransactionJournal $journal
     *
     * @throws FireflyException
     */
    public function update(TransactionJournal $journal)
    {
        /** @var \FireflyIII\Database\TransactionJournal $repos */
        $repos = App::make('FireflyIII\Database\TransactionJournal');

        $data             = Input::except('_token');
        $data['currency'] = 'EUR';
        $data['what']     = strtolower($journal->transactionType->type);


        switch (Input::get('post_submit_action')) {
            case 'update':
            case 'return_to_edit':
                $messageBag = $repos->update($journal, $data);
                if ($messageBag->count() == 0) {
                    // has been saved, return to index:
                    Session::flash('success', 'Transaction updated!');
                    Event::fire('transactionJournal.update', [$journal]); // new and used.

                    /*
                     * Also trigger on both transactions.
                     */
                    /** @var Transaction $transaction */
                    foreach ($journal->transactions as $transaction) {
                        Event::fire('transaction.update', [$transaction]);
                    }

                    if (Input::get('post_submit_action') == 'return_to_edit') {
                        return Redirect::route('transactions.edit', $journal->id)->withInput();
                    } else {
                        return Redirect::route('transactions.index', $data['what']);
                    }
                } else {
                    Session::flash('error', 'Could not update transaction: ' . $journal->getErrors()->first());

                    return Redirect::route('transactions.edit', $journal->id)->withInput()->withErrors(
                        $journal->getErrors()
                    );
                }

                break;
            case 'validate_only':
                $messageBags = $repos->validate($data);

                Session::flash('warnings', $messageBags['warnings']);
                Session::flash('successes', $messageBags['successes']);
                Session::flash('errors', $messageBags['errors']);

                return Redirect::route('transactions.edit', $journal->id)->withInput();
                break;
            default:
                throw new FireflyException('Method ' . Input::get('post_submit_action') . ' not implemented yet.');
                break;
        }


    }

}