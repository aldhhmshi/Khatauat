(() => {
  'use strict';

  const root = document.querySelector('[data-calculator-engine]');
  if (!root) return;

  const host = root.querySelector('[data-engine-form]');
  const resultBox = root.querySelector('[data-engine-result]');
  const resultPrimary = root.querySelector('[data-result-primary]');
  const resultLines = root.querySelector('[data-result-lines]');

  const money = (n) => new Intl.NumberFormat('ar-SA', {
    style: 'currency', currency: 'SAR', maximumFractionDigits: 2,
  }).format(Number.isFinite(n) ? n : 0);

  const num = (n) => new Intl.NumberFormat('ar-SA', {
    maximumFractionDigits: 2,
  }).format(Number.isFinite(n) ? n : 0);

  const value = (form, name) => {
    const el = form.elements[name];
    return el ? (parseFloat(el.value || '0') || 0) : 0;
  };

  const dateValue = (form, name) => {
    const raw = form.elements[name]?.value;
    return raw ? new Date(`${raw}T00:00:00`) : null;
  };

  const dateDiff = (a, b) => {
    if (!a || !b || b < a) return null;
    let years = b.getFullYear() - a.getFullYear();
    let months = b.getMonth() - a.getMonth();
    let days = b.getDate() - a.getDate();
    if (days < 0) {
      months -= 1;
      days += new Date(b.getFullYear(), b.getMonth(), 0).getDate();
    }
    if (months < 0) {
      years -= 1;
      months += 12;
    }
    return {
      years,
      months,
      days,
      totalDays: Math.round((b - a) / 86400000),
      fullMonths: years * 12 + months,
    };
  };

  const field = (name, label, type = 'number', opts = {}) => {
    const attrs = [
      `name="${name}"`, `type="${type}"`,
      opts.step !== undefined ? `step="${opts.step}"` : '',
      opts.min !== undefined ? `min="${opts.min}"` : '',
      opts.max !== undefined ? `max="${opts.max}"` : '',
      opts.value !== undefined ? `value="${opts.value}"` : '',
      opts.placeholder ? `placeholder="${opts.placeholder}"` : '',
    ].filter(Boolean).join(' ');
    return `<label>${label}<input ${attrs}></label>`;
  };

  const select = (name, label, options) => (
    `<label>${label}<select name="${name}">${options.map(([v, t]) => `<option value="${v}">${t}</option>`).join('')}</select></label>`
  );

  const line = (label, val) => `<div><span>${label}</span><b>${val}</b></div>`;

  const definitions = {
    vat: {
      fields: () => (
        select('mode', 'طريقة الحساب', [
          ['add', 'إضافة الضريبة إلى مبلغ قبل الضريبة'],
          ['remove', 'استخراج الضريبة من مبلغ شامل الضريبة'],
        ]) +
        field('amount', 'المبلغ', 'number', { step: '0.01', min: 0 }) +
        field('rate', 'نسبة الضريبة %', 'number', { step: '0.01', min: 0, value: 15 })
      ),
      calculate: (form) => {
        const amount = value(form, 'amount');
        const rate = value(form, 'rate') / 100;
        if (form.mode.value === 'remove') {
          const base = amount / (1 + rate);
          const tax = amount - base;
          return [money(base), [
            ['قيمة الضريبة', money(tax)],
            ['المبلغ شامل الضريبة', money(amount)],
          ]];
        }
        const tax = amount * rate;
        return [money(amount + tax), [
          ['المبلغ قبل الضريبة', money(amount)],
          ['قيمة الضريبة', money(tax)],
        ]];
      },
    },

    'end-of-service': {
      fields: () => (
        field('wage', 'الأجر الفعلي الأخير', 'number', { step: '0.01', min: 0 }) +
        '<div class="v217-engine-grid">' +
        field('years', 'سنوات الخدمة', 'number', { min: 0, value: 0 }) +
        field('months', 'أشهر الخدمة', 'number', { min: 0, value: 0 }) +
        field('days', 'أيام الخدمة', 'number', { min: 0, value: 0 }) +
        '</div>' +
        select('reason', 'سبب انتهاء العلاقة', [
          ['normal', 'انتهاء العقد / إنهاء غير الاستقالة'],
          ['resignation', 'استقالة'],
        ])
      ),
      calculate: (form) => {
        const wage = value(form, 'wage');
        const serviceYears = value(form, 'years') + value(form, 'months') / 12 + value(form, 'days') / 365;
        const base = serviceYears <= 5
          ? serviceYears * 0.5 * wage
          : 2.5 * wage + (serviceYears - 5) * wage;
        let multiplier = 1;
        if (form.reason.value === 'resignation') {
          multiplier = serviceYears < 2 ? 0 : serviceYears <= 5 ? 1 / 3 : serviceYears < 10 ? 2 / 3 : 1;
        }
        return [money(base * multiplier), [
          ['المكافأة قبل نسبة الاستقالة', money(base)],
          ['مدة الخدمة التقريبية', `${num(serviceYears)} سنة`],
          ['نسبة الاستحقاق المحتسبة', `${num(multiplier * 100)}%`],
        ]];
      },
    },

    'zakat-simple': {
      fields: () => (
        field('amount', 'مبلغ النقد الزكوي', 'number', { step: '0.01', min: 0 }) +
        field('rate', 'نسبة الزكاة %', 'number', { step: '0.001', min: 0, value: 2.5 })
      ),
      calculate: (form) => {
        const amount = value(form, 'amount');
        const rate = value(form, 'rate') / 100;
        return [money(amount * rate), [
          ['الوعاء المدخل', money(amount)],
          ['النسبة المستخدمة', `${num(rate * 100)}%`],
        ]];
      },
    },

    'zakat-detailed': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('cash', 'النقود', 'number', { min: 0 }) +
        field('gold', 'الذهب والفضة بالقيمة', 'number', { min: 0 }) +
        field('shares', 'الأسهم والصناديق الزكوية', 'number', { min: 0 }) +
        field('realestate', 'عقارات/عروض تجارة زكوية', 'number', { min: 0 }) +
        field('other', 'بنود زكوية أخرى', 'number', { min: 0 }) +
        field('liabilities', 'التزامات قابلة للحسم وفق حالتك', 'number', { min: 0 }) +
        '</div>' +
        field('rate', 'النسبة %', 'number', { step: '0.001', min: 0, value: 2.5 })
      ),
      calculate: (form) => {
        const assets = ['cash', 'gold', 'shares', 'realestate', 'other']
          .reduce((sum, key) => sum + value(form, key), 0);
        const base = Math.max(0, assets - value(form, 'liabilities'));
        const rate = value(form, 'rate') / 100;
        return [money(base * rate), [
          ['إجمالي البنود', money(assets)],
          ['الوعاء بعد الحسم المدخل', money(base)],
          ['النسبة', `${num(rate * 100)}%`],
        ]];
      },
    },

    'salary-net': {
      fields: () => (
        field('gross', 'إجمالي الراتب الشهري', 'number', { min: 0 }) +
        '<div class="v217-engine-grid">' +
        field('gosi', 'استقطاع التأمينات الفعلي', 'number', { min: 0 }) +
        field('other', 'استقطاعات أخرى', 'number', { min: 0 }) +
        '</div>'
      ),
      calculate: (form) => {
        const gross = value(form, 'gross');
        const deductions = value(form, 'gosi') + value(form, 'other');
        return [money(Math.max(0, gross - deductions)), [
          ['إجمالي الاستقطاعات', money(deductions)],
          ['نسبة الاستقطاع من الإجمالي', gross ? `${num(deductions / gross * 100)}%` : '0%'],
        ]];
      },
    },

    'wage-converter': {
      fields: () => (
        field('salary', 'الراتب الشهري', 'number', { min: 0 }) +
        '<div class="v217-engine-grid">' +
        field('days', 'مقسوم الأيام الشهري', 'number', { min: 1, value: 30 }) +
        field('hours', 'ساعات العمل في اليوم', 'number', { min: 1, value: 8 }) +
        '</div>'
      ),
      calculate: (form) => {
        const salary = value(form, 'salary');
        const days = value(form, 'days') || 30;
        const hours = value(form, 'hours') || 8;
        const daily = salary / days;
        return [money(daily), [
          ['الأجر اليومي', money(daily)],
          ['الأجر الساعي', money(daily / hours)],
        ]];
      },
    },

    'service-duration': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('start', 'تاريخ بداية الخدمة', 'date') +
        field('end', 'تاريخ النهاية', 'date') +
        '</div>'
      ),
      calculate: (form) => {
        const d = dateDiff(dateValue(form, 'start'), dateValue(form, 'end'));
        if (!d) return ['تحقق من التواريخ', []];
        return [`${d.years} سنة و${d.months} شهر و${d.days} يوم`, [
          ['إجمالي الأيام', num(d.totalDays)],
          ['إجمالي الأشهر الكاملة', num(d.fullMonths)],
        ]];
      },
    },

    'annual-leave': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('entitled', 'الرصيد المستحق بالأيام', 'number', { min: 0 }) +
        field('carry', 'رصيد مرحل', 'number', { min: 0 }) +
        field('used', 'أيام مستخدمة', 'number', { min: 0 }) +
        field('salary', 'الراتب الشهري (اختياري)', 'number', { min: 0 }) +
        '</div>'
      ),
      calculate: (form) => {
        const remaining = Math.max(0, value(form, 'entitled') + value(form, 'carry') - value(form, 'used'));
        const salary = value(form, 'salary');
        return [`${num(remaining)} يوم`, [
          ['الرصيد المتبقي', `${num(remaining)} يوم`],
          ['قيمة إرشادية للرصيد على أساس 30 يومًا', salary ? money(salary / 30 * remaining) : '—'],
        ]];
      },
    },

    'loan-payment': {
      fields: () => (
        field('principal', 'مبلغ التمويل', 'number', { min: 0 }) +
        '<div class="v217-engine-grid">' +
        field('rate', 'النسبة السنوية الاسمية %', 'number', { step: '0.01', min: 0 }) +
        field('months', 'مدة السداد بالأشهر', 'number', { min: 1, value: 60 }) +
        '</div>'
      ),
      calculate: (form) => {
        const principal = value(form, 'principal');
        const months = Math.max(1, value(form, 'months'));
        const monthlyRate = value(form, 'rate') / 1200;
        const payment = monthlyRate === 0
          ? principal / months
          : principal * monthlyRate * Math.pow(1 + monthlyRate, months) / (Math.pow(1 + monthlyRate, months) - 1);
        const total = payment * months;
        return [money(payment), [
          ['إجمالي السداد التقديري', money(total)],
          ['تكلفة التمويل التقديرية', money(total - principal)],
        ]];
      },
    },

    'debt-ratio': {
      fields: () => (
        field('income', 'إجمالي الدخل الشهري', 'number', { min: 0 }) +
        '<div class="v217-engine-grid">' +
        field('salaryDeduct', 'التزامات مرتبطة باستقطاع الراتب', 'number', { min: 0 }) +
        field('other', 'التزامات ائتمانية شهرية أخرى', 'number', { min: 0 }) +
        '</div>' +
        select('retired', 'الحالة', [['no', 'موظف / غير متقاعد'], ['yes', 'متقاعد']])
      ),
      calculate: (form) => {
        const income = value(form, 'income');
        const salaryDeduct = value(form, 'salaryDeduct');
        const other = value(form, 'other');
        const total = salaryDeduct + other;
        const totalRatio = income ? total / income * 100 : 0;
        const salaryRatio = income ? salaryDeduct / income * 100 : 0;
        const referenceLimit = form.retired.value === 'yes' ? 25 : 33.33;
        return [`${num(totalRatio)}%`, [
          ['نسبة الالتزامات إلى الدخل', `${num(totalRatio)}%`],
          ['نسبة استقطاع الراتب', `${num(salaryRatio)}%`],
          ['الحد المرجعي لاستقطاع الراتب', `${num(referenceLimit)}%`],
          ['المؤشر', salaryRatio <= referenceLimit ? 'ضمن الحد المرجعي' : 'أعلى من الحد المرجعي'],
        ]];
      },
    },

    discount: {
      fields: () => (
        field('price', 'السعر قبل الخصم', 'number', { min: 0 }) +
        field('discount', 'نسبة الخصم %', 'number', { min: 0, max: 100 })
      ),
      calculate: (form) => {
        const price = value(form, 'price');
        const rate = Math.min(100, value(form, 'discount')) / 100;
        const discount = price * rate;
        return [money(price - discount), [
          ['قيمة الخصم', money(discount)],
          ['السعر قبل الخصم', money(price)],
        ]];
      },
    },

    percentage: {
      fields: () => (
        select('mode', 'نوع الحساب', [
          ['of', 'كم تساوي X% من Y؟'],
          ['ratio', 'X تمثل كم % من Y؟'],
          ['change', 'نسبة التغير من X إلى Y'],
        ]) +
        '<div class="v217-engine-grid">' +
        field('x', 'القيمة X', 'number', { step: '0.01' }) +
        field('y', 'القيمة Y', 'number', { step: '0.01' }) +
        '</div>'
      ),
      calculate: (form) => {
        const x = value(form, 'x');
        const y = value(form, 'y');
        if (form.mode.value === 'of') {
          return [num(x / 100 * y), [['المعادلة', `${num(x)}% × ${num(y)}`]]];
        }
        if (form.mode.value === 'ratio') {
          return [y ? `${num(x / y * 100)}%` : '—', [['المعادلة', 'X ÷ Y × 100']]];
        }
        return [x ? `${num((y - x) / x * 100)}%` : '—', [['التغير العددي', num(y - x)]]];
      },
    },

    age: {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('birth', 'تاريخ الميلاد', 'date') +
        field('asof', 'الحساب حتى تاريخ', 'date', { value: new Date().toISOString().slice(0, 10) }) +
        '</div>'
      ),
      calculate: (form) => {
        const birth = dateValue(form, 'birth');
        const asOf = dateValue(form, 'asof');
        const d = dateDiff(birth, asOf);
        if (!d) return ['تحقق من التواريخ', []];
        let hijri = '—';
        try {
          hijri = new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura', {
            year: 'numeric', month: 'long', day: 'numeric',
          }).format(birth);
        } catch (_) {}
        return [`${d.years} سنة و${d.months} شهر و${d.days} يوم`, [
          ['إجمالي الأيام', num(d.totalDays)],
          ['تاريخ الميلاد هجريًا', hijri],
        ]];
      },
    },

    'date-difference': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('start', 'التاريخ الأول', 'date') +
        field('end', 'التاريخ الثاني', 'date') +
        '</div>'
      ),
      calculate: (form) => {
        const d = dateDiff(dateValue(form, 'start'), dateValue(form, 'end'));
        if (!d) return ['تحقق من التواريخ', []];
        return [`${d.years} سنة و${d.months} شهر و${d.days} يوم`, [
          ['إجمالي الأيام', num(d.totalDays)],
          ['إجمالي الأشهر الكاملة', num(d.fullMonths)],
        ]];
      },
    },

    'contract-duration': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('start', 'تاريخ بدء العقد', 'date') +
        field('end', 'تاريخ انتهاء العقد', 'date') +
        '</div>'
      ),
      calculate: (form) => {
        const d = dateDiff(dateValue(form, 'start'), dateValue(form, 'end'));
        if (!d) return ['تحقق من التواريخ', []];
        return [`${d.years} سنة و${d.months} شهر و${d.days} يوم`, [
          ['مدة العقد بالأيام', num(d.totalDays)],
          ['مدة العقد بالأشهر الكاملة', num(d.fullMonths)],
        ]];
      },
    },

    'electricity-consumption': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('watts', 'قدرة الجهاز بالواط', 'number', { min: 0 }) +
        field('count', 'عدد الأجهزة', 'number', { min: 1, value: 1 }) +
        field('hours', 'ساعات التشغيل يوميًا', 'number', { min: 0, value: 8 }) +
        field('days', 'عدد الأيام', 'number', { min: 1, value: 30 }) +
        '</div>' +
        field('tariff', 'التعرفة التي تريد استخدامها (ريال/ك.و.س)', 'number', { step: '0.001', min: 0 })
      ),
      calculate: (form) => {
        const kwh = value(form, 'watts') / 1000 * value(form, 'count') * value(form, 'hours') * value(form, 'days');
        const tariff = value(form, 'tariff');
        return [`${num(kwh)} ك.و.س`, [
          ['الاستهلاك التقديري', `${num(kwh)} ك.و.س`],
          ['التكلفة حسب التعرفة المدخلة', tariff ? money(kwh * tariff) : 'أدخل التعرفة لإظهار التكلفة'],
        ]];
      },
    },

    'business-setup-cost': {
      fields: () => (
        '<div class="v217-engine-grid">' +
        field('cr', 'السجل التجاري', 'number', { min: 0 }) +
        field('chamber', 'الغرفة التجارية', 'number', { min: 0 }) +
        field('municipal', 'الرخص البلدية', 'number', { min: 0 }) +
        field('address', 'العنوان الوطني/الخدمات البريدية', 'number', { min: 0 }) +
        field('licenses', 'تراخيص النشاط', 'number', { min: 0 }) +
        field('other', 'تكاليف أخرى', 'number', { min: 0 }) +
        '</div>'
      ),
      calculate: (form) => {
        const total = ['cr', 'chamber', 'municipal', 'address', 'licenses', 'other']
          .reduce((sum, key) => sum + value(form, key), 0);
        return [money(total), [
          ['إجمالي البنود المدخلة', money(total)],
          ['ملاحظة', 'الرسوم تختلف حسب الكيان والنشاط والمدينة؛ أدخل القيم الحالية من رحلتك.'],
        ]];
      },
    },

    'date-converter': {
      fields: () => (
        select('mode', 'اتجاه التحويل', [
          ['g2h', 'ميلادي إلى هجري (أم القرى)'],
          ['h2g', 'هجري (أم القرى) إلى ميلادي'],
        ]) +
        '<div data-greg>' + field('greg', 'التاريخ الميلادي', 'date') + '</div>' +
        '<div data-hijri hidden class="v217-engine-grid">' +
        field('hy', 'السنة الهجرية', 'number', { min: 1 }) +
        field('hm', 'الشهر الهجري', 'number', { min: 1, max: 12 }) +
        field('hd', 'اليوم الهجري', 'number', { min: 1, max: 30 }) +
        '</div>'
      ),
      setup: (form) => {
        const sync = () => {
          form.querySelector('[data-greg]').hidden = form.mode.value !== 'g2h';
          form.querySelector('[data-hijri]').hidden = form.mode.value !== 'h2g';
        };
        form.mode.addEventListener('change', sync);
        sync();
      },
      calculate: (form) => {
        const hijriParts = (date) => {
          const parts = new Intl.DateTimeFormat('en-US-u-ca-islamic-umalqura', {
            year: 'numeric', month: 'numeric', day: 'numeric',
          }).formatToParts(date);
          const out = {};
          parts.forEach((part) => {
            if (['year', 'month', 'day'].includes(part.type)) out[part.type] = Number(part.value);
          });
          return out;
        };

        if (form.mode.value === 'g2h') {
          const date = dateValue(form, 'greg');
          if (!date) return ['أدخل التاريخ', []];
          try {
            const p = hijriParts(date);
            return [`${p.day}/${p.month}/${p.year} هـ`, [['التقويم', 'أم القرى حسب دعم المتصفح']]];
          } catch (_) {
            return ['التقويم غير مدعوم في هذا المتصفح', []];
          }
        }

        const hy = value(form, 'hy');
        const hm = value(form, 'hm');
        const hd = value(form, 'hd');
        if (!hy || !hm || !hd) return ['أكمل التاريخ الهجري', []];

        try {
          const approximateGregorianYear = Math.round(hy * 0.970224 + 621.5774);
          const start = new Date(approximateGregorianYear - 1, 0, 1);
          const end = new Date(approximateGregorianYear + 2, 11, 31);
          let found = null;
          for (let cursor = new Date(start); cursor <= end; cursor.setDate(cursor.getDate() + 1)) {
            const p = hijriParts(cursor);
            if (p.year === hy && p.month === hm && p.day === hd) {
              found = new Date(cursor);
              break;
            }
          }
          if (!found) return ['تعذر مطابقة التاريخ', []];
          return [new Intl.DateTimeFormat('ar-SA', {
            year: 'numeric', month: 'long', day: 'numeric',
          }).format(found), [
            ['ISO', found.toISOString().slice(0, 10)],
            ['التقويم المصدر', 'أم القرى حسب دعم المتصفح'],
          ]];
        } catch (_) {
          return ['التقويم غير مدعوم في هذا المتصفح', []];
        }
      },
    },
  };

  const definition = definitions[root.dataset.calculatorEngine];
  if (!definition) {
    host.innerHTML = '<div class="v217-engine-error">هذه الحاسبة تحتاج قاعدة حساب مفعلة.</div>';
    return;
  }

  host.innerHTML = `<form class="v217-engine" data-engine>${definition.fields()}<button class="btn btn-primary v217-calc-submit" type="submit">احسب الآن</button></form>`;
  const form = host.querySelector('form');
  if (definition.setup) definition.setup(form);

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    const [main, details] = definition.calculate(form);
    resultPrimary.textContent = main;
    resultLines.innerHTML = details.map(([label, val]) => line(label, val)).join('');
    resultBox.hidden = false;
    resultBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
})();
