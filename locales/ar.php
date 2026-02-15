<?php
// Arabic

return [
    'nav.main' => 'القائمة الرئيسية',
    'nav.menu' => 'القائمة',
    'nav.rates' => 'الأسعار',
    'nav.portfolio' => 'المحفظة',
    'nav.language' => 'اللغة',
    'nav.language_name.tr' => 'تركية',
    'nav.language_name.en' => 'إنجليزية',
    'nav.language_name.ar' => 'عربية',
    'nav.language_name.de' => 'ألمانية',
    'nav.language_name.fr' => 'فرنسية',
    'nav.language_switch_to' => 'تغيير اللغة إلى {{language}}',
    'nav.theme_toggle' => 'تبديل المظهر الداكن/الفاتح',
    'nav.login' => 'تسجيل الدخول',
    'nav.logout' => 'تسجيل الخروج',
    'nav.logout_action' => 'تسجيل الخروج',

    'auth.login_title' => 'تسجيل الدخول',
    'auth.username' => 'اسم المستخدم',
    'auth.password' => 'كلمة المرور',
    'auth.login' => 'دخول',
    'auth.back' => 'العودة إلى الرئيسية',
    'auth.error_empty' => 'اسم المستخدم وكلمة المرور مطلوبان.',
    'auth.error_invalid' => 'اسم المستخدم أو كلمة المرور غير صحيحة.',
    'auth.error_rate_limit' => 'محاولات كثيرة جدًا. يرجى المحاولة بعد بضع دقائق.',
    'auth.error_captcha' => 'فشل التحقق الأمني. يرجى المحاولة مرة أخرى.',

    'index.page_title' => 'أسعار الصرف',
    'index.last_update' => 'آخر تحديث: {{datetime}}',
    'index.table.currency' => 'العملة',
    'index.table.code' => 'الرمز',
    'index.table.bank_buy' => 'شراء البنك',
    'index.table.bank_sell' => 'بيع البنك',
    'index.table.change' => 'التغيير',
    'index.table.caption' => 'أسعار صرف {{bank}}',
    'index.empty_title' => 'لا توجد بيانات أسعار صرف بعد',
    'index.empty_desc' => 'قم بتشغيل المهمة المجدولة لجلب الأسعار:',
    'index.refresh.status_ready' => 'التحديث التلقائي للأسعار نشط كل 5 دقائق.',
    'index.refresh.status_updated' => 'تم تحديث الأسعار في __TIME__.',

    'index.chart.title' => 'رسم بياني للأسعار',
    'index.chart.currency' => 'العملة',
    'index.chart.days' => 'الفترة',
    'index.chart.days_unit' => 'يوم',
    'index.chart.aria_label' => 'رسم بياني لتاريخ أسعار الصرف',

    'index.widgets.title' => 'ملخص',
    'index.widgets.top_movers' => 'الأكثر تغييرًا',
    'index.widgets.portfolio_summary' => 'ملخص المحفظة',

    'converter.title' => 'محول العملات',
    'converter.amount' => 'المبلغ',
    'converter.from' => 'من',
    'converter.to' => 'إلى',
    'converter.rate_type' => 'نوع السعر',
    'converter.bank' => 'البنك',
    'converter.result' => 'النتيجة',

    'portfolio.page_title' => 'المحفظة',
    'portfolio.message.added' => 'تمت الإضافة إلى المحفظة.',
    'portfolio.message.error' => 'خطأ: {{error}}',
    'portfolio.message.error_generic' => 'حدث خطأ غير متوقع. يرجى المحاولة مرة أخرى.',
    'portfolio.message.deleted' => 'تم الحذف من المحفظة.',
    'portfolio.message.updated' => 'تم تحديث المحفظة.',
    'portfolio.message.not_found' => 'لم يتم العثور على السجل المراد حذفه.',

    'portfolio.form.edit_title' => 'تعديل المحفظة',
    'portfolio.form.update' => 'تحديث',
    'portfolio.form.cancel' => 'إلغاء',

    'portfolio.summary.total_cost' => 'التكلفة الإجمالية',
    'portfolio.summary.current_value' => 'القيمة الحالية',
    'portfolio.summary.profit_loss' => 'الربح / الخسارة',

    'portfolio.form.title' => 'إضافة إلى المحفظة',
    'portfolio.form.currency' => 'العملة',
    'portfolio.form.bank' => 'البنك',
    'portfolio.form.select' => 'اختر',
    'portfolio.form.select_optional' => 'اختر (اختياري)',
    'portfolio.form.amount' => 'المبلغ',
    'portfolio.form.buy_rate' => 'سعر الشراء (TRY)',
    'portfolio.form.buy_date' => 'تاريخ الشراء',
    'portfolio.form.notes' => 'ملاحظات',
    'portfolio.form.notes_placeholder' => 'ملاحظة اختيارية',
    'portfolio.form.submit' => 'إضافة',
    'portfolio.form.error.currency_code' => 'يرجى اختيار عملة صالحة.',
    'portfolio.form.error.bank_slug' => 'يرجى اختيار بنك صالح.',
    'portfolio.form.error.amount' => 'أدخل مبلغًا صالحًا أكبر من 0.',
    'portfolio.form.error.buy_rate' => 'أدخل سعر شراء صالحًا أكبر من 0.',
    'portfolio.form.error.buy_date' => 'أدخل تاريخًا صالحًا بتنسيق YYYY-MM-DD.',
    'portfolio.form.error.notes' => 'يجب ألا تتجاوز الملاحظات 500 حرف.',

    'portfolio.table.title' => 'المحفظة ({{count}} عنصر)',
    'portfolio.table.caption' => 'ممتلكات المحفظة والأداء',
    'portfolio.table.currency' => 'العملة',
    'portfolio.table.amount' => 'المبلغ',
    'portfolio.table.buy_rate' => 'سعر الشراء',
    'portfolio.table.current_rate' => 'السعر الحالي',
    'portfolio.table.cost' => 'التكلفة (TRY)',
    'portfolio.table.value' => 'القيمة (TRY)',
    'portfolio.table.pl_percent' => 'ر/خ (%)',
    'portfolio.table.date' => 'التاريخ',
    'portfolio.table.actions' => 'الإجراءات',
    'portfolio.table.delete_confirm' => 'هل أنت متأكد أنك تريد حذف هذا السجل؟',
    'portfolio.table.delete_action' => 'حذف {{currency}} من المحفظة',
    'portfolio.table.edit_action' => 'تعديل {{currency}}',

    'portfolio.empty_title' => 'محفظتك فارغة',
    'portfolio.empty_desc' => 'استخدم النموذج أعلاه لإضافة عملات أو معادن ثمينة.',
    'portfolio.export_csv' => 'تصدير CSV',

    'portfolio.analytics.title' => 'تحليلات المحفظة',
    'portfolio.analytics.distribution' => 'توزيع العملات',
    'portfolio.analytics.annualized_return' => 'العائد السنوي',
    'portfolio.analytics.per_year' => '/سنة',
    'portfolio.analytics.approximation' => '(تقريب بسيط)',

    'footer.github' => 'GitHub',
    'footer.code_of_conduct' => 'قواعد السلوك',
    'footer.license' => 'الترخيص',

    'observability.title' => 'المراقبة',
    'observability.bank_stats' => 'إحصائيات البنوك',
    'observability.stats_period' => 'آخر 7 أيام',
    'observability.bank' => 'البنك',
    'observability.last_scrape' => 'آخر جلب',
    'observability.runs_7d' => 'التشغيلات (7 أيام)',
    'observability.success_rate' => 'معدل النجاح',
    'observability.avg_duration' => 'متوسط المدة',
    'observability.recent_logs' => 'السجلات الأخيرة',
    'observability.time' => 'الوقت',
    'observability.status' => 'الحالة',
    'observability.rates' => 'الأسعار',
    'observability.duration' => 'المدة',
    'observability.message' => 'الرسالة',
    'observability.errors' => 'الأخطاء',
    'observability.table_changed' => '[تم تغيير الجدول]',
    'observability.status_success' => 'نجاح',
    'observability.status_error' => 'خطأ',
    'observability.status_warning' => 'تحذير',

    'admin.title' => 'الإدارة',
    'admin.banks' => 'البنوك',
    'admin.currencies' => 'العملات',
    'admin.users' => 'المستخدمون',
    'admin.health' => 'صحة النظام',
    'admin.last_rate_update' => 'آخر تحديث للأسعار',
    'admin.update_rates_now' => 'تحديث الأسعار الآن',
    'admin.clear_cache' => 'مسح ذاكرة التخزين المؤقت',
    'admin.cache_cleared' => 'تم مسح ذاكرة التخزين المؤقت',
    'admin.rates_updated_success' => 'تم تحديث الأسعار بنجاح!',
    'admin.rates_updated_error' => 'خطأ في تحديث الأسعار',
    'admin.status' => 'الحالة',
    'admin.active' => 'نشط',
    'admin.inactive' => 'غير نشط',
    'admin.activate' => 'تفعيل',
    'admin.deactivate' => 'تعطيل',

    'common.skip_to_content' => 'انتقل إلى المحتوى الرئيسي',
    'common.opens_new_tab' => '(يفتح في علامة تبويب جديدة)',
    'common.not_available' => '—',
    'common.invalid_request' => 'طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.',
    'common.forbidden' => 'محظور',

    'api.error.too_many_requests' => 'طلبات كثيرة جدًا',
    'api.error.body_too_large' => 'حجم الطلب كبير جدًا',
    'api.error.post_required' => 'طريقة POST مطلوبة',
    'api.error.post_put_patch_required' => 'طريقة POST أو PUT أو PATCH مطلوبة',
    'api.error.post_delete_required' => 'طريقة POST أو DELETE مطلوبة',
    'api.error.invalid_csrf' => 'رمز CSRF غير صالح',
    'api.error.valid_id_required' => 'معرّف صالح مطلوب',
    'api.error.no_fields_to_update' => 'لا توجد حقول للتحديث',
    'api.error.currency_required' => 'معامل العملة مطلوب',
    'api.error.alert_fields_required' => 'currency_code و condition_type (above|below|change_pct) و threshold مطلوبة',
    'api.error.invalid_params' => 'معاملات طلب غير صالحة',
    'api.error.internal' => 'حدث خطأ داخلي',
    'api.error.auth_required' => 'المصادقة مطلوبة',
    'api.error.auth_not_configured' => 'المصادقة مطلوبة ولكن غير مهيأة',
    'api.message.added_portfolio' => 'تمت الإضافة إلى المحفظة',
    'api.message.updated' => 'تم التحديث',
    'api.message.deleted' => 'تم الحذف',
    'api.message.not_found' => 'غير موجود',
    'api.message.alert_created' => 'تم إنشاء التنبيه',

    'alert.subject' => 'تنبيه Cybokron: {{currency}} = {{rate}}',
    'alert.body.title' => 'تنبيه أسعار Cybokron',
    'alert.body.currency' => 'العملة: {{currency}}',
    'alert.body.condition' => 'الشرط: {{type}} (الحد: {{threshold}})',
    'alert.body.sell_rate' => 'سعر البيع: {{rate}}',
    'alert.body.buy_rate' => 'سعر الشراء: {{rate}}',
    'alert.body.change' => 'التغيير: {{change}}%',
    'alert.body.last_update' => 'آخر تحديث: {{datetime}}',
    'alert.body.footer' => 'هذا تنبيه تلقائي من Cybokron.',

    'chart.label.buy' => 'شراء',
    'chart.label.sell' => 'بيع',

    'theme.switch_to_light' => 'التبديل إلى الوضع الفاتح',
    'theme.switch_to_dark' => 'التبديل إلى الوضع الداكن',

    'csv.currency' => 'العملة',
    'csv.amount' => 'المبلغ',
    'csv.buy_rate' => 'سعر الشراء',
    'csv.buy_date' => 'تاريخ الشراء',
    'csv.current_rate' => 'السعر الحالي',
    'csv.cost_try' => 'التكلفة (TRY)',
    'csv.value_try' => 'القيمة (TRY)',
    'csv.pl_percent' => 'ر/خ (%)',
    'csv.notes' => 'ملاحظات',
    'csv.group' => 'المجموعة',
    'csv.tags' => 'الوسوم',

    'admin.slug' => 'الرابط',
    'admin.code' => 'الرمز',
    'admin.type' => 'النوع',
    'admin.role' => 'الدور',
    'admin.created' => 'تاريخ الإنشاء',
    'admin.id' => 'المعرّف',

    'admin.widget_management' => 'أدوات الصفحة الرئيسية',
    'admin.widget_management_desc' => 'إعادة ترتيب والتحكم في إظهار أقسام الصفحة الرئيسية.',
    'admin.widget_bank_selector' => 'اختيار البنك',
    'admin.widget_converter' => 'محوّل العملات',
    'admin.widget_summary' => 'الملخص',
    'admin.widget_chart' => 'رسم الأسعار البياني',
    'admin.widget_rates' => 'جداول الأسعار',
    'admin.widget_drag_desc' => 'اسحب الأدوات لإعادة ترتيبها. استخدم المفاتيح لإظهار أو إخفاء الأقسام.',
    'admin.widget_config_updated' => 'تم حفظ إعدادات الأدوات!',
    'admin.visible' => 'مرئي',
    'admin.hidden' => 'مخفي',
    'admin.drag_drop_hint' => 'سحب وإفلات',
    'admin.order_saving' => 'جارٍ حفظ الترتيب...',
    'admin.order_saved' => 'تم حفظ الترتيب!',
    'admin.order_save_error' => 'فشل حفظ الترتيب. يرجى التحديث والمحاولة مرة أخرى.',
    'admin.drag_drop_desc' => 'اسحب الصفوف لإعادة الترتيب. يتم حفظ الترتيب تلقائياً.',
    'admin.save_order' => 'حفظ الترتيب',
    'admin.rate_order_updated' => 'تم تحديث ترتيب الأسعار.',
    'admin.filter_bank' => 'تصفية حسب البنك',
    'admin.all' => 'الكل',
    'admin.homepage_rates' => 'أسعار الصفحة الرئيسية',
    'admin.homepage_rates_desc' => 'إدارة الأسعار المعروضة على الصفحة الرئيسية.',
    'admin.homepage_visibility' => 'ظهور الصفحة الرئيسية',
    'admin.show' => 'إظهار',
    'admin.hide' => 'إخفاء',
    'admin.rate_shown_on_homepage' => 'سيتم عرض السعر على الصفحة الرئيسية.',
    'admin.rate_hidden_from_homepage' => 'السعر مخفي من الصفحة الرئيسية.',
    'admin.all_banks' => 'جميع البنوك',
    'admin.default_bank_setting' => 'إعداد البنك الافتراضي',
    'admin.default_bank_desc' => 'اختر البنك الافتراضي للصفحة الرئيسية.',
    'admin.default_bank' => 'البنك الافتراضي',
    'admin.default_bank_updated' => 'تم تحديث البنك الافتراضي.',
    'admin.save' => 'حفظ',
    'admin.chart_defaults' => 'إعدادات الرسم البياني الافتراضية',
    'admin.chart_defaults_desc' => 'تعيين العملة والفترة الافتراضية للرسم البياني.',
    'admin.chart_currency' => 'العملة الافتراضية',
    'admin.chart_days' => 'الفترة الافتراضية',
    'admin.chart_defaults_updated' => 'تم تحديث إعدادات الرسم البياني.',

    'api.endpoint.rates' => 'أحدث أسعار الصرف',
    'api.endpoint.rates_compact' => 'بيانات أسعار مختصرة للعملاء',
    'api.endpoint.rates_bank' => 'أسعار بنك محدد',
    'api.endpoint.rates_currency' => 'أسعار عملة محددة',
    'api.endpoint.history' => 'تاريخ الأسعار مع دعم الصفحات',
    'api.endpoint.portfolio' => 'ملخص المحفظة',
    'api.endpoint.portfolio_add' => 'إضافة إلى المحفظة',
    'api.endpoint.portfolio_update' => 'تحديث سجل المحفظة',
    'api.endpoint.portfolio_delete' => 'حذف من المحفظة',
    'api.endpoint.banks' => 'قائمة البنوك',
    'api.endpoint.currencies' => 'قائمة العملات',
    'api.endpoint.version' => 'إصدار التطبيق',
    'api.endpoint.ai_model' => 'حالة نموذج OpenRouter',

    'portfolio.groups.title' => 'المجموعات',
    'portfolio.groups.manage' => 'إدارة المجموعات',
    'portfolio.groups.add' => 'مجموعة جديدة',
    'portfolio.groups.edit' => 'تعديل المجموعة',
    'portfolio.groups.delete' => 'حذف المجموعة',
    'portfolio.groups.delete_confirm' => 'هل أنت متأكد أنك تريد حذف هذه المجموعة؟ سيتم فصل العناصر.',
    'portfolio.groups.name' => 'اسم المجموعة',
    'portfolio.groups.color' => 'اللون',
    'portfolio.groups.icon' => 'الأيقونة',
    'portfolio.groups.icon_placeholder' => 'الأيقونة (إيموجي)',
    'portfolio.groups.items' => '{{count}} عناصر',
    'portfolio.groups.no_group' => 'بدون مجموعة',
    'portfolio.groups.all' => 'الكل',
    'portfolio.groups.added' => 'تم إنشاء المجموعة.',
    'portfolio.groups.updated' => 'تم تحديث المجموعة.',
    'portfolio.groups.deleted' => 'تم حذف المجموعة.',
    'portfolio.groups.error' => 'فشلت عملية المجموعة.',
    'portfolio.groups.empty' => 'لم يتم إنشاء مجموعات بعد.',
    'portfolio.groups.delete_confirm_count' => 'تحتوي هذه المجموعة على {{count}} عناصر. الحذف سيفصلها. متابعة؟',

    'portfolio.tags.title' => 'الوسوم',
    'portfolio.tags.add' => 'وسم جديد',
    'portfolio.tags.name' => 'اسم الوسم',
    'portfolio.tags.color' => 'اللون',
    'portfolio.tags.items' => '{{count}} عناصر',
    'portfolio.tags.no_tag' => 'بدون وسم',
    'portfolio.tags.all' => 'جميع الوسوم',
    'portfolio.tags.added' => 'تم إنشاء الوسم.',
    'portfolio.tags.updated' => 'تم تحديث الوسم.',
    'portfolio.tags.deleted' => 'تم حذف الوسم.',
    'portfolio.tags.error' => 'فشلت عملية الوسم.',
    'portfolio.tags.delete_confirm' => 'هل أنت متأكد أنك تريد حذف هذا الوسم؟',
    'portfolio.tags.delete_confirm_count' => 'هذا الوسم معين لـ {{count}} عناصر. الحذف سيزيله. متابعة؟',
    'portfolio.tags.assigned' => 'تم تعيين الوسم.',
    'portfolio.tags.removed' => 'تم إزالة الوسم.',
    'portfolio.tags.empty' => 'لم يتم إنشاء وسوم بعد.',
    'portfolio.tags.inline_add' => '+ وسم',
    'portfolio.tags.inline_placeholder' => 'إضافة وسم...',

    'portfolio.form.group' => 'المجموعة',
    'portfolio.form.group_optional' => 'اختر مجموعة (اختياري)',
    'portfolio.form.tags' => 'الوسوم',
    'portfolio.form.tags_placeholder' => 'اختر الوسوم',

    'portfolio.filter.title' => 'الفلاتر',
    'portfolio.filter.group' => 'المجموعة',
    'portfolio.filter.tag' => 'الوسم',
    'portfolio.filter.date_from' => 'من',
    'portfolio.filter.date_to' => 'إلى',
    'portfolio.filter.apply' => 'تصفية',
    'portfolio.filter.clear' => 'مسح',
    'portfolio.filter.currency_type' => 'النوع',
    'portfolio.filter.all_types' => 'الكل',
    'portfolio.filter.precious_metals' => 'الذهب والمعادن الثمينة',
    'portfolio.filter.fiat_only' => 'العملات فقط',

    'portfolio.table.group' => 'المجموعة',
    'portfolio.table.tags' => 'الوسوم',
    'portfolio.inline.assign_tag' => 'تعيين وسم',
    'portfolio.inline.remove_tag' => 'إزالة وسم',

    'portfolio.bulk.selected' => '{{count}} عناصر محددة',
    'portfolio.bulk.assign_group' => 'تعيين للمجموعة',
    'portfolio.bulk.remove_group' => 'إزالة من المجموعة',
    'portfolio.bulk.assign_tag' => 'إضافة وسم',
    'portfolio.bulk.remove_tag' => 'إزالة وسم',
    'portfolio.bulk.success' => 'تم تحديث {{count}} عناصر.',
    'portfolio.bulk.select_group' => 'اختر مجموعة',
    'portfolio.bulk.select_tag' => 'اختر وسم',
    'portfolio.bulk.no_selection' => 'يرجى تحديد عنصر واحد على الأقل.',
    'portfolio.bulk.group_actions' => 'إجراءات المجموعة',
    'portfolio.bulk.tag_actions' => 'إجراءات الوسم',

    'portfolio.manage.title' => 'المجموعات والوسوم',
    'portfolio.manage.tab_groups' => 'المجموعات',
    'portfolio.manage.tab_tags' => 'الوسوم',
    'portfolio.manage.collapse' => 'طي',
    'portfolio.manage.expand' => 'توسيع',

    'portfolio.analytics.group_summary' => 'ملخص المجموعة',
    'portfolio.analytics.group_cost' => 'التكلفة الإجمالية',
    'portfolio.analytics.group_value' => 'القيمة الحالية',
    'portfolio.analytics.group_pl' => 'الربح/الخسارة',

    'portfolio.manage.tab_goals' => 'الأهداف',
    'portfolio.goals.add' => 'هدف جديد',
    'portfolio.goals.name' => 'اسم الهدف',
    'portfolio.goals.name_placeholder' => 'مثال: هدف الذهب، مدخرات العملات...',
    'portfolio.goals.target_value' => 'القيمة المستهدفة (₺)',
    'portfolio.goals.target_type' => 'نوع التتبع',
    'portfolio.goals.type_value' => 'القيمة الحالية',
    'portfolio.goals.type_cost' => 'التكلفة الإجمالية',
    'portfolio.goals.type_amount' => 'الكمية',
    'portfolio.goals.currency' => 'العملة',
    'portfolio.goals.select_currency' => '— اختر العملة —',
    'portfolio.goals.target_amount' => 'الكمية المستهدفة',
    'portfolio.goals.bank' => 'البنك',
    'portfolio.goals.all_banks' => 'جميع البنوك',
    'portfolio.goals.type_currency_value' => 'القيمة بالعملة',
    'portfolio.goals.target_currency_value_label' => 'القيمة المستهدفة (العملة)',
    'portfolio.goals.sources' => 'المصادر (مجموعة، وسم، أو عنصر)',
    'portfolio.goals.source_item' => 'عنصر فردي',
    'portfolio.goals.items' => 'عناصر',
    'portfolio.goals.delete_confirm' => 'هل أنت متأكد أنك تريد حذف هذا الهدف؟',
    'portfolio.goals.empty' => 'لم يتم إنشاء أهداف بعد.',
    'portfolio.goals.added' => 'تم إنشاء الهدف.',
    'portfolio.goals.updated' => 'تم تحديث الهدف.',
    'portfolio.goals.deleted' => 'تم حذف الهدف.',
    'portfolio.goals.error' => 'فشلت عملية الهدف.',
    'portfolio.goals.source_added' => 'تمت إضافة المصدر للهدف.',
    'portfolio.goals.source_removed' => 'تمت إزالة المصدر من الهدف.',
    'portfolio.goals.type_percent' => 'نسبة الربح',
    'portfolio.goals.target_percent' => 'النسبة المستهدفة (%)',
    'portfolio.goals.percent_date_mode' => 'وضع التاريخ',
    'portfolio.goals.percent_mode_all' => 'بدون تاريخ (الكل)',
    'portfolio.goals.percent_mode_range' => 'نطاق التاريخ',
    'portfolio.goals.percent_mode_since_first' => 'منذ أول شراء',
    'portfolio.goals.percent_mode_weighted' => 'المتوسط المرجح',
    'portfolio.goals.percent_date_start' => 'البداية',
    'portfolio.goals.percent_date_end' => 'النهاية',
    'portfolio.goals.percent_period' => 'المدة (أشهر)',
    'portfolio.goals.type_cagr' => 'معدل النمو السنوي المركب (CAGR)',
    'portfolio.goals.target_cagr' => 'CAGR المستهدف (%/سنة)',
    'portfolio.goals.type_drawdown' => 'حد الخسارة',
    'portfolio.goals.target_drawdown' => 'أقصى حد خسارة (%)',
    'portfolio.goals.drawdown_limit' => 'الحد:',
    'portfolio.goals.favorites' => 'المفضلة',
    'portfolio.goals.filter_group' => 'المجموعة',
    'portfolio.goals.filter_tag' => 'الوسم',
    'portfolio.goals.filter_all_currencies' => 'جميع العملات',
    'portfolio.goals.filter_clear' => 'مسح',
    'portfolio.goals.favorite_toggled' => 'تم تحديث حالة المفضلة.',
    'portfolio.goals.favorite_add' => 'إضافة إلى المفضلة',
    'portfolio.goals.favorite_remove' => 'إزالة من المفضلة',
    'portfolio.goals.deadline' => 'الموعد النهائي للهدف',
    'portfolio.goals.deadline_none' => 'لا يوجد',
    'portfolio.goals.deadline_1m' => 'شهر واحد',
    'portfolio.goals.deadline_3m' => '3 أشهر',
    'portfolio.goals.deadline_6m' => '6 أشهر',
    'portfolio.goals.deadline_9m' => '9 أشهر',
    'portfolio.goals.deadline_1y' => 'سنة واحدة',
    'portfolio.goals.deadline_custom' => 'تاريخ مخصص',
    'portfolio.goals.deadline_remaining' => ':months أشهر متبقية',
    'portfolio.goals.deadline_expired' => 'انتهت المدة',
    'portfolio.goals.period' => 'الفترة',
    'portfolio.goals.period_all' => 'الكل',
    'portfolio.goals.period_7d' => 'أسبوعي',
    'portfolio.goals.period_14d' => 'أسبوعان',
    'portfolio.goals.period_1m' => 'شهر واحد',
    'portfolio.goals.period_3m' => '3 أشهر',
    'portfolio.goals.period_6m' => '6 أشهر',
    'portfolio.goals.period_9m' => '9 أشهر',
    'portfolio.goals.period_1y' => 'سنة واحدة',
    'portfolio.goals.period_custom' => 'مخصص',
    'portfolio.goals.deposit_label' => 'لو كان في وديعة',
    'portfolio.goals.deposit_better' => 'فرق',
    'portfolio.goals.deposit_worse' => 'ميزة',
    'common.add' => 'إضافة',
    'common.select_all' => 'تحديد الكل',
    'common.delete' => 'حذف',
    'common.remove' => 'إزالة',
    'common.save' => 'حفظ',
    'common.cancel' => 'إلغاء',

    // System Config
    'admin.system_config' => 'تكوين النظام',
    'admin.system_config_desc' => 'إعدادات التكوين النشطة من config.php (للقراءة فقط).',
    'admin.config_section_security' => 'الأمان',
    'admin.config_section_scraping' => 'جلب البيانات',
    'admin.config_section_market' => 'ساعات السوق',
    'admin.config_section_alerts' => 'الإشعارات',
    'admin.config_section_api' => 'حدود API',
    'admin.config_section_system' => 'النظام',
    'admin.config_enabled' => 'مفعّل',
    'admin.config_disabled' => 'معطّل',
    'admin.config_not_set' => 'غير محدد',
    'admin.config_set' => 'محدد',

    'admin.cfg_security_headers' => 'رؤوس الأمان',
    'admin.cfg_cli_cron' => 'CLI Cron فقط',
    'admin.cfg_login_limit' => 'حد تسجيل الدخول',
    'admin.cfg_portfolio_auth' => 'مصادقة المحفظة',
    'admin.cfg_scrape_timeout' => 'مهلة',
    'admin.cfg_retry_count' => 'عدد المحاولات',
    'admin.cfg_ai_repair' => 'إصلاح AI',
    'admin.cfg_ai_model' => 'نموذج AI',
    'admin.cfg_update_interval' => 'فترة التحديث',
    'admin.cfg_market_open' => 'الافتتاح',
    'admin.cfg_market_close' => 'الإغلاق',
    'admin.cfg_market_days' => 'أيام التداول',
    'admin.cfg_history_retention' => 'الاحتفاظ بالسجل',
    'admin.cfg_alert_cooldown' => 'فترة الانتظار',
    'admin.cfg_rate_webhook' => 'Webhook الأسعار',
    'admin.cfg_read_limit' => 'حد القراءة',
    'admin.cfg_write_limit' => 'حد الكتابة',
    'admin.cfg_timezone' => 'المنطقة الزمنية',
    'admin.cfg_locale' => 'اللغة',
    'admin.cfg_auto_update' => 'التحديث التلقائي',
    'admin.cfg_logging' => 'السجلات',
    'admin.cfg_db_persistent' => 'اتصال DB دائم',
    'admin.day_mon' => 'إثنين',
    'admin.day_tue' => 'ثلاثاء',
    'admin.day_wed' => 'أربعاء',
    'admin.day_thu' => 'خميس',
    'admin.day_fri' => 'جمعة',
    'admin.day_sat' => 'سبت',
    'admin.day_sun' => 'أحد',

    // OpenRouter AI
    'nav.openrouter' => 'OpenRouter AI',
    'openrouter.title' => 'إدارة OpenRouter AI',
    'openrouter.connection_status' => 'حالة الاتصال',
    'openrouter.test_connection' => 'اختبار الاتصال',
    'openrouter.test_success' => 'الاتصال ناجح',
    'openrouter.test_error' => 'خطأ في الاتصال',
    'openrouter.model_active' => 'النموذج النشط',
    'openrouter.model_default' => 'النموذج الافتراضي (config)',
    'openrouter.model_change' => 'تغيير النموذج',
    'openrouter.model_updated' => 'تم تحديث النموذج.',
    'openrouter.model_placeholder' => 'مثال: z-ai/glm-5',
    'openrouter.key_status' => 'حالة مفتاح API',
    'openrouter.key_set' => 'محدد',
    'openrouter.key_not_set' => 'غير محدد',
    'openrouter.key_last_chars' => 'آخر 4 أحرف',
    'openrouter.ai_repair_stats' => 'إحصائيات إصلاح AI',
    'openrouter.last_ai_call' => 'آخر استدعاء AI',
    'openrouter.cooldown_active' => 'فترة الانتظار نشطة',
    'openrouter.cooldown_inactive' => 'لا فترة انتظار',
    'openrouter.table_change_logs' => 'سجلات تغيير الجدول',
    'openrouter.config_summary' => 'ملخص التكوين',
    'openrouter.no_logs' => 'لا توجد سجلات بعد.',
    'openrouter.response_time' => 'وقت الاستجابة',
    'openrouter.rates_extracted' => 'الأسعار المستخرجة',
    'openrouter.never' => 'أبداً',
    'openrouter.ago' => 'مضت',
    'openrouter.save' => 'حفظ',
    'openrouter.bank' => 'البنك',
    'openrouter.status' => 'الحالة',
    'openrouter.time' => 'الوقت',
    'openrouter.message' => 'الرسالة',
    'openrouter.rates_count' => 'عدد الأسعار',
    'openrouter.duration' => 'المدة',
    'openrouter.table_changed' => '🔄 تم تغيير الجدول',
    'openrouter.enabled' => 'مفعّل',
    'openrouter.disabled' => 'معطّل',

    'admin.openrouter_settings' => 'إعدادات OpenRouter AI',
    'admin.openrouter_settings_desc' => 'إدارة مفتاح API وإعدادات النموذج. يتم الحفظ في قاعدة البيانات.',
    'admin.openrouter_settings_saved' => 'تم حفظ إعدادات OpenRouter.',
    'admin.openrouter_api_key_label' => 'مفتاح API',
    'admin.openrouter_model_label' => 'النموذج',
    'admin.openrouter_model_hint' => 'معرّف نموذج OpenRouter (مثال: z-ai/glm-5)',
    'admin.openrouter_toggle_key' => 'إظهار/إخفاء المفتاح',
    'admin.openrouter_key_source_db' => 'يتم القراءة من قاعدة البيانات (تم التعيين من لوحة الإدارة)',
    'admin.openrouter_key_source_config' => 'يتم القراءة من config.php',
    'admin.openrouter_key_not_configured' => 'لم يتم تعيين مفتاح API بعد',
    'admin.openrouter_panel_link' => 'لوحة OpenRouter',

    // SEO & Noindex
    'admin.seo_settings' => 'إعدادات SEO',
    'admin.seo_settings_desc' => 'إدارة فهرسة محركات البحث وإعدادات SEO.',
    'admin.noindex_label' => 'إخفاء من محركات البحث (noindex)',
    'admin.noindex_desc' => 'عند التفعيل، يتم إضافة علامة noindex إلى جميع الصفحات لمنع محركات البحث من فهرسة الموقع.',
    'admin.noindex_enabled' => 'noindex نشط — الموقع مخفي من محركات البحث',
    'admin.noindex_disabled' => 'noindex معطّل — الموقع مرئي في محركات البحث',
    'admin.noindex_updated' => 'تم تحديث إعداد noindex.',
    'seo.index_description' => 'أسعار صرف حية، أسعار الذهب والفضة والمعادن الثمينة. مقارنة البنوك ومحول العملات لمتابعة السوق.',
    'seo.portfolio_description' => 'إدارة محفظة العملات والمعادن الثمينة. تتبع الأرباح/الخسائر والمجموعات والوسوم.',
    'seo.admin_description' => 'لوحة إدارة Cybokron Exchange Rate & Portfolio Tracking.',
    'seo.observability_description' => 'إحصائيات جلب البنوك، صحة النظام وسجلات الأداء.',
    'seo.login_description' => 'صفحة تسجيل الدخول إلى Cybokron Exchange Rate & Portfolio Tracking.',
    'seo.openrouter_description' => 'تكوين OpenRouter AI وإحصائيات إصلاح الأسعار.',

    // Data Retention
    'admin.retention_title' => 'مدة الاحتفاظ بالبيانات',
    'admin.retention_desc' => 'تحديد مدة الاحتفاظ ببيانات سجل الأسعار.',
    'admin.retention_label' => 'مدة الاحتفاظ',
    'admin.retention_month' => 'شهر',
    'admin.retention_year' => 'سنة',
    'admin.retention_hint' => 'سيتم حذف بيانات سجل الأسعار الأقدم من هذه المدة تلقائياً بواسطة مهمة التنظيف المجدولة.',
    'admin.retention_updated' => 'تم تحديث مدة الاحتفاظ بالبيانات.',

    'admin.deposit_rate_title' => 'سعر فائدة الودائع',
    'admin.deposit_rate_desc' => 'سعر الفائدة الصافي السنوي المستخدم لمقارنة الأهداف.',
    'admin.deposit_rate_label' => 'سعر الفائدة الصافي السنوي (%)',
    'admin.deposit_rate_updated' => 'تم تحديث سعر فائدة الودائع.',

    // Self-Healing
    'selfhealing.title' => 'سجل الإصلاح التلقائي',
    'selfhealing.active_configs' => 'إعدادات الإصلاح النشطة',
    'selfhealing.no_active_configs' => 'لا توجد إعدادات إصلاح نشطة.',
    'selfhealing.repair_logs' => 'سجلات الإصلاح (آخر 30)',
    'selfhealing.no_repair_logs' => 'لا توجد سجلات إصلاح بعد.',
    'selfhealing.step' => 'خطوة',
    'selfhealing.manual_trigger' => 'تشغيل الإصلاح يدوياً',
    'selfhealing.config_deactivated' => 'تم تعطيل إعداد الإصلاح.',
    'selfhealing.no_active_config' => 'لم يتم العثور على إعداد إصلاح نشط لهذا البنك.',
    'selfhealing.deactivate_confirm' => 'هل أنت متأكد من تعطيل إعداد الإصلاح هذا؟',
    'selfhealing.repair_triggered' => 'تم تشغيل الإصلاح لـ {{bank}}.',
    'selfhealing.repair_failed' => 'فشل الإصلاح: {{error}}',

    // Live Repair
    'repair.live_title' => 'الإصلاح المباشر',
    'repair.live_desc' => 'شاهد خط أنابيب الإصلاح التلقائي في الوقت الفعلي. اختر بنكاً وابدأ عملية الإصلاح.',
    'repair.select_bank' => 'اختر البنك',
    'repair.btn.start' => 'بدء الإصلاح',
    'repair.btn.running' => 'جارٍ التنفيذ...',
    'repair.stepper_aria' => 'خطوات تقدم الإصلاح',
    'repair.step.fetch_html' => 'جلب صفحة البنك',
    'repair.step.check_enabled' => 'التحقق من تفعيل الإصلاح التلقائي',
    'repair.step.cooldown_check' => 'التحقق من فترة الانتظار',
    'repair.step.generate_config' => 'إنشاء التكوين عبر AI',
    'repair.step.validate_config' => 'التحقق من التكوين',
    'repair.step.save_config' => 'حفظ التكوين',
    'repair.step.github_commit' => 'الإيداع في GitHub',
    'repair.step.pipeline_complete' => 'اكتمال خط الأنابيب',
    'repair.summary.success' => 'نجح الإصلاح',
    'repair.summary.failed' => 'فشل الإصلاح',
    'repair.summary.rates_found' => 'أسعار وُجدت',
];
